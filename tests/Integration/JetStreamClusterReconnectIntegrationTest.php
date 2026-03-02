<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\SignalCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Client\ClientNotConnectedException;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamDrainTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\PubAck;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Tests\Support\NatsJetStreamClusterHarness;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class JetStreamClusterReconnectIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::requireMode();
        NatsJetStreamClusterHarness::awaitClusterReady();
    }

    public function testPublisherSurvivesNodeStopWithFailover(): void
    {
        self::requireMode();

        // Arrange
        $suffix      = bin2hex(random_bytes(6));
        $stream      = 'ORDERS_JS_CLUSTER_' . strtoupper($suffix);
        $subject     = sprintf('orders.cluster.%s.created', $suffix);
        $publisher   = $this->createReconnectClient();
        $adminClient = $this->createReconnectClient(enablePing: false);
        $this->connectClientWithRetry($adminClient, 10.0);
        $admin = new JetStreamAdmin(new JetStreamControlPlaneTransport($adminClient));
        $this->createStreamWithRetry($admin, new StreamConfig($stream, [$subject], 'file', 3), 15.0);
        $stoppedHost = null;

        try {
            $this->connectClientWithRetry($publisher, 10.0);
            $stoppedHost = 'n1';

            // Act
            NatsJetStreamClusterHarness::stopNode($stoppedHost);
            $this->awaitJetStreamOperational($publisher, 30.0);
            $ack = $this->publishWithRetry(
                new JetStreamPublisher($publisher),
                subject: $subject,
                payload: 'after-failover',
                options: PublishOptions::create(msgId: 'cluster-' . $suffix),
                timeoutSeconds: 30.0,
            );

            // Assert
            self::assertSame($stream, $ack->getStream());
            self::assertGreaterThanOrEqual(1, $ack->getSeq());
            self::assertSame(ClientState::CONNECTED, $publisher->getState());
            self::assertNotSame($stoppedHost, $publisher->getCurrentServer()?->getHost());
        } finally {
            if ($stoppedHost !== null) {
                NatsJetStreamClusterHarness::startNode($stoppedHost);
                NatsJetStreamClusterHarness::waitNodeAcceptsInfo($stoppedHost);
            }
            $publisher->disconnect();
            $this->deleteStreamSafely($admin, $stream);
            $adminClient->disconnect();
        }
    }

    public function testPullConsumeLoopSurvivesNodeStopStart(): void
    {
        self::requireMode();

        // Arrange
        $suffix       = bin2hex(random_bytes(6));
        $stream       = 'ORDERS_JS_PULL_' . strtoupper($suffix);
        $subject      = sprintf('orders.pull.cluster.%s.created', $suffix);
        $consumerName = 'C1';
        $adminClient  = $this->createReconnectClient(enablePing: false);
        $this->connectClientWithRetry($adminClient, 10.0);
        $admin       = new JetStreamAdmin(new JetStreamControlPlaneTransport($adminClient));
        $publisher   = $this->createReconnectClient();
        $pullClient  = $this->createReconnectClient();
        $handle      = null;
        $stoppedHost = null;

        $this->createStreamWithRetry($admin, new StreamConfig($stream, [$subject], 'file', 3), 15.0);
        $this->createConsumerWithRetry(
            $admin,
            $stream,
            new ConsumerConfig($consumerName, filterSubject: $subject),
            10.0,
        );

        try {
            $this->connectClientWithRetry($publisher, 10.0);
            $this->connectClientWithRetry($pullClient, 10.0);

            $pub = new JetStreamPublisher($publisher);
            for ($index = 1; $index <= 10; $index++) {
                $this->publishWithRetry(
                    $pub,
                    subject: $subject,
                    payload: 'before-' . $index,
                    options: PublishOptions::create(msgId: sprintf('before-%s-%d', $suffix, $index)),
                    timeoutSeconds: 10.0,
                );
            }

            $pull     = (new JetStreamPullConsumerFactory(new JetStreamControlPlaneTransport($pullClient)))->create($stream, $consumerName);
            $handle   = $pull->consume(new PullConsumeOptions(batch: 5, expiresMs: 1_000));
            $acker    = $handle->getAcker();
            $consumed = 0;

            while ($consumed < 3) {
                $message = $handle->next(2_000);
                self::assertNotNull($message);
                $acker->ack($message);
                $consumed++;
            }

            $stoppedHost = 'n1';

            // Act
            NatsJetStreamClusterHarness::stopNode($stoppedHost);
            $this->awaitJetStreamOperational($publisher, 20.0);

            for ($index = 1; $index <= 10; $index++) {
                $this->publishWithRetry(
                    $pub,
                    subject: $subject,
                    payload: 'after-' . $index,
                    options: PublishOptions::create(msgId: sprintf('after-%s-%d', $suffix, $index)),
                    timeoutSeconds: 20.0,
                );
            }

            $afterReceived = 0;
            $deadline      = microtime(true) + 15.0;
            while ($afterReceived < 5 && microtime(true) < $deadline) {
                $message = $handle->next(1_000);
                if ($message === null) {
                    delay(0.05);
                    continue;
                }

                if (str_starts_with($message->getPayload(), 'after-')) {
                    $afterReceived++;
                }

                $acker->ack($message);
            }

            // Assert
            self::assertGreaterThanOrEqual(5, $afterReceived);
        } finally {
            if ($handle !== null) {
                $handle->stop();
                $handle->awaitStopped(3_000);
            }
            if ($stoppedHost !== null) {
                NatsJetStreamClusterHarness::startNode($stoppedHost);
                NatsJetStreamClusterHarness::waitNodeAcceptsInfo($stoppedHost);
            }
            $pullClient->disconnect();
            $publisher->disconnect();
            $this->deleteConsumerSafely($admin, $stream, $consumerName);
            $this->deleteStreamSafely($admin, $stream);
            $adminClient->disconnect();
        }
    }

    public function testDrainSemanticsUnderClusterReconnect(): void
    {
        self::requireMode();

        // Arrange
        $suffix       = bin2hex(random_bytes(6));
        $stream       = 'ORDERS_JS_DRAIN_' . strtoupper($suffix);
        $subject      = sprintf('orders.drain.cluster.%s.created', $suffix);
        $consumerName = 'C1';
        $adminClient  = $this->createReconnectClient(enablePing: false);
        $this->connectClientWithRetry($adminClient, 10.0);
        $admin  = new JetStreamAdmin(new JetStreamControlPlaneTransport($adminClient));
        $client = $this->createReconnectClient(enablePing: false);
        $this->connectClientWithRetry($client, 10.0);
        $publisher = new JetStreamPublisher($client);

        $this->createStreamWithRetry($admin, new StreamConfig($stream, [$subject], 'file', 3), 15.0);
        $this->createConsumerWithRetry(
            $admin,
            $stream,
            new ConsumerConfig($consumerName, filterSubject: $subject),
            10.0,
        );
        $publisher->publish($subject, 'm1');
        $publisher->publish($subject, 'm2');

        $pull   = (new JetStreamPullConsumerFactory(new JetStreamControlPlaneTransport($client)))->create($stream, $consumerName);
        $handle = $pull->consume(new PullConsumeOptions(batch: 1, expiresMs: 1_000));
        $acker  = $handle->getAcker();

        try {
            $first = $handle->next(2_000);
            self::assertNotNull($first);

            try {
                $handle->drain(1_000);
                self::fail('Expected JetStreamDrainTimeoutException');
            } catch (JetStreamDrainTimeoutException) {
            }

            $acker->ack($first);
            $second = $handle->next(2_000);
            self::assertNotNull($second);
            $acker->ack($second);

            $handle->drain(2_000);
            self::assertNull($handle->next(500));
        } finally {
            $handle->stop();
            $handle->awaitStopped(3_000);
            $client->disconnect();
            $this->deleteConsumerSafely($admin, $stream, $consumerName);
            $this->deleteStreamSafely($admin, $stream);
            $adminClient->disconnect();
        }
    }

    private function createReconnectClient(bool $enablePing = false): Client
    {
        $cancellation            = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);
        $connector               = new RetrySocketConnector(new DnsSocketConnector());
        $connectionConfiguration = new ConnectionConfiguration('n1', 4222);
        $connection              = new Connection($connector, $connectionConfiguration);
        $eventDispatcher         = new EventDispatcher();
        $connectionInfo          = new ConnectInfo(
            verbose: false,
            pedantic: false,
            tls_required: false,
            lang: 'php',
            version: PHP_VERSION,
            protocol: 1,
            headers: true,
        );
        $storage                 = new SubscriptionStorage();
        $messageDispatcher       = new MessageDispatcher($connectionInfo, $storage);

        return new Client(
            configuration: new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 30,
                reconnectBackoffInitialMs: 50,
                reconnectBackoffMaxMs: 500,
                reconnectBackoffMultiplier: 2.0,
                reconnectJitterFraction: 0.0,
                pingEnabled: $enablePing,
                pingIntervalMs: 500,
                pingTimeoutMs: 2_000,
                pingReconnectOnTimeout: true,
                servers: NatsJetStreamClusterHarness::getSeedServers(),
            ),
            cancellation: $cancellation,
            connection: $connection,
            eventDispatcher: $eventDispatcher,
            messageDispatcher: $messageDispatcher,
            storage: $storage,
        );
    }

    private function publishWithRetry(
        JetStreamPublisher $publisher,
        string $subject,
        string $payload,
        PublishOptions $options,
        float $timeoutSeconds,
    ): PubAck {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            try {
                return $publisher->publish($subject, $payload, $options);
            } catch (ClientNotConnectedException) {
                delay(0.05);
            } catch (\Throwable) {
                delay(0.05);
            }
        }

        self::fail(sprintf('JetStream publish did not recover within %.1fs', $timeoutSeconds));
    }

    private function connectClientWithRetry(Client $client, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            try {
                $client->connect();

                return;
            } catch (\Throwable) {
                delay(0.05);
            }
        }

        self::fail(sprintf('Client did not connect within %.1fs', $timeoutSeconds));
    }

    private function createStreamWithRetry(JetStreamAdmin $admin, StreamConfig $config, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastErr  = 'unknown';
        while (microtime(true) < $deadline) {
            try {
                $admin->createStream($config);

                return;
            } catch (\Throwable $exception) {
                $lastErr = sprintf('%s: %s', $exception::class, $exception->getMessage());
                delay(0.05);
            }
        }

        self::fail(sprintf('JetStream stream was not created within %.1fs; last error: %s', $timeoutSeconds, $lastErr));
    }

    private function createConsumerWithRetry(
        JetStreamAdmin $admin,
        string $stream,
        ConsumerConfig $config,
        float $timeoutSeconds,
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastErr  = 'unknown';
        while (microtime(true) < $deadline) {
            try {
                $admin->createOrUpdateConsumer($stream, $config);

                return;
            } catch (\Throwable $exception) {
                $lastErr = sprintf('%s: %s', $exception::class, $exception->getMessage());
                delay(0.05);
            }
        }

        self::fail(sprintf('JetStream consumer was not created within %.1fs; last error: %s', $timeoutSeconds, $lastErr));
    }

    private function deleteStreamSafely(JetStreamAdmin $admin, string $stream): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            try {
                $admin->deleteStream($stream);

                return;
            } catch (\Throwable) {
                delay(0.05);
            }
        }
    }

    private function deleteConsumerSafely(JetStreamAdmin $admin, string $stream, string $consumer): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            try {
                $admin->deleteConsumer($stream, $consumer);

                return;
            } catch (\Throwable) {
                delay(0.05);
            }
        }
    }

    private function awaitJetStreamOperational(Client $client, float $timeoutSeconds): void
    {
        $deadline  = microtime(true) + $timeoutSeconds;
        $transport = new JetStreamControlPlaneTransport($client);

        while (microtime(true) < $deadline) {
            try {
                if ($client->getState() !== ClientState::CONNECTED) {
                    $client->connect();
                }

                $transport->request('INFO', [], 1_000);

                return;
            } catch (\Throwable) {
            }

            delay(0.05);
        }

        self::fail(sprintf('Client did not become JetStream-operational within %.1fs', $timeoutSeconds));
    }

    private static function requireMode(): void
    {
        if ((string) (getenv('NATS_JS_CLUSTER') ?: '0') !== '1') {
            self::markTestSkipped('JetStream cluster integration is disabled. Run with NATS_JS_CLUSTER=1.');
        }
    }
}
