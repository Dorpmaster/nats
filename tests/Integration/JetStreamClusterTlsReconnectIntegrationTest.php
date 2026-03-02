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
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\PubAck;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Tests\Support\NatsJetStreamClusterHarness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

#[Group('integration-jetstream-cluster-tls')]
final class JetStreamClusterTlsReconnectIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::requireMode();
        NatsJetStreamClusterHarness::awaitClusterReady();
    }

    public function testTlsPublisherSurvivesNodeStopWithFailover(): void
    {
        self::requireMode();

        // Arrange
        $suffix      = bin2hex(random_bytes(6));
        $stream      = 'ORDERS_JS_TLS_' . strtoupper($suffix);
        $subject     = sprintf('orders.cluster.tls.%s.created', $suffix);
        $publisher   = $this->createReconnectTlsClient();
        $adminClient = $this->createReconnectTlsClient(enablePing: false);
        $this->connectClientWithRetry($adminClient, 10.0);
        $admin = new JetStreamAdmin(new JetStreamControlPlaneTransport($adminClient));
        $this->createStreamWithRetry($admin, new StreamConfig($stream, [$subject], 'file', 3), 15.0);
        $stoppedHost = null;

        try {
            $this->connectClientWithRetry($publisher, 10.0);
            $stoppedHost = 'n1';

            // Act
            NatsJetStreamClusterHarness::stopNode($stoppedHost);
            $this->awaitJetStreamOperational($publisher, 20.0);
            $ack = $this->publishWithRetry(
                new JetStreamPublisher($publisher),
                subject: $subject,
                payload: 'after-failover',
                options: PublishOptions::create(msgId: 'cluster-tls-' . $suffix),
                timeoutSeconds: 20.0,
            );

            // Assert
            self::assertSame($stream, $ack->getStream());
            self::assertGreaterThanOrEqual(1, $ack->getSeq());
            self::assertSame(ClientState::CONNECTED, $publisher->getState());
            self::assertNotSame($stoppedHost, $publisher->getCurrentServer()?->getHost());
        } finally {
            if ($stoppedHost !== null) {
                NatsJetStreamClusterHarness::startNode($stoppedHost);
                NatsJetStreamClusterHarness::waitNodeAcceptsInfoTls($stoppedHost);
            }
            $publisher->disconnect();
            $this->deleteStreamSafely($admin, $stream);
            $adminClient->disconnect();
        }
    }

    public function testTlsPullFetchSurvivesNodeStopStart(): void
    {
        self::requireMode();

        // Arrange
        $suffix       = bin2hex(random_bytes(6));
        $stream       = 'ORDERS_JS_TLS_PULL_' . strtoupper($suffix);
        $subject      = sprintf('orders.pull.tls.cluster.%s.created', $suffix);
        $consumerName = 'C1';
        $adminClient  = $this->createReconnectTlsClient(enablePing: false);
        $this->connectClientWithRetry($adminClient, 10.0);
        $admin       = new JetStreamAdmin(new JetStreamControlPlaneTransport($adminClient));
        $publisher   = $this->createReconnectTlsClient();
        $consumer    = $this->createReconnectTlsClient();
        $stoppedHost = 'n1';

        $this->createStreamWithRetry($admin, new StreamConfig($stream, [$subject], 'file', 3), 15.0);
        $this->createConsumerWithRetry(
            $admin,
            $stream,
            new ConsumerConfig($consumerName, filterSubject: $subject),
            10.0,
        );

        try {
            $this->connectClientWithRetry($publisher, 10.0);
            $this->connectClientWithRetry($consumer, 10.0);
            $jsPublisher = new JetStreamPublisher($publisher);
            for ($index = 1; $index <= 6; $index++) {
                $this->publishWithRetry(
                    $jsPublisher,
                    subject: $subject,
                    payload: 'before-' . $index,
                    options: PublishOptions::create(msgId: sprintf('tls-before-%s-%d', $suffix, $index)),
                    timeoutSeconds: 10.0,
                );
            }

            // Act
            NatsJetStreamClusterHarness::stopNode($stoppedHost);
            $this->awaitJetStreamOperational($publisher, 20.0);

            for ($index = 1; $index <= 6; $index++) {
                $this->publishWithRetry(
                    $jsPublisher,
                    subject: $subject,
                    payload: 'after-' . $index,
                    options: PublishOptions::create(msgId: sprintf('tls-after-%s-%d', $suffix, $index)),
                    timeoutSeconds: 20.0,
                );
            }

            $pull   = (new JetStreamPullConsumerFactory(new JetStreamControlPlaneTransport($consumer)))->create($stream, $consumerName);
            $result = $pull->fetch(batch: 4, expiresMs: 3_000);

            // Assert
            self::assertGreaterThanOrEqual(1, $result->getReceivedCount());
        } finally {
            NatsJetStreamClusterHarness::startNode($stoppedHost ?? 'n1');
            NatsJetStreamClusterHarness::waitNodeAcceptsInfoTls($stoppedHost ?? 'n1');
            $consumer->disconnect();
            $publisher->disconnect();
            $this->deleteConsumerSafely($admin, $stream, $consumerName);
            $this->deleteStreamSafely($admin, $stream);
            $adminClient->disconnect();
        }
    }

    public function testTlsVerifyPeerRejectsWrongCaForJetStreamCluster(): void
    {
        self::requireMode();

        // Arrange
        $client = $this->createReconnectTlsClient(
            new TlsConfiguration(
                enabled: true,
                verifyPeer: true,
                caFile: dirname(__DIR__) . '/Support/tls/ca.pem',
                clientCertFile: self::clientCertFile(),
                clientKeyFile: self::clientKeyFile(),
            ),
        );

        // Assert
        self::expectException(ConnectionException::class);
        self::expectExceptionMessage('TLS');

        // Act
        $client->connect();
    }

    private function createReconnectTlsClient(TlsConfiguration|null $tls = null, bool $enablePing = false): Client
    {
        $cancellation            = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);
        $connector               = new RetrySocketConnector(new DnsSocketConnector());
        $connectionConfiguration = new ConnectionConfiguration('n1', 4222, tls: $tls ?? $this->tlsConfiguration());
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
                pingIntervalMs: 1_000,
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

        self::fail(sprintf('JetStream TLS publish did not recover within %.1fs', $timeoutSeconds));
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

        self::fail(sprintf('TLS client did not connect within %.1fs', $timeoutSeconds));
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

        self::fail(sprintf('JetStream TLS stream was not created within %.1fs; last error: %s', $timeoutSeconds, $lastErr));
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

        self::fail(sprintf('JetStream TLS consumer was not created within %.1fs; last error: %s', $timeoutSeconds, $lastErr));
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
                delay(0.05);
            }
        }

        self::fail(sprintf('TLS client did not become JetStream-operational within %.1fs', $timeoutSeconds));
    }

    private function tlsConfiguration(): TlsConfiguration
    {
        return new TlsConfiguration(
            enabled: true,
            verifyPeer: true,
            caFile: self::caFile(),
            clientCertFile: self::clientCertFile(),
            clientKeyFile: self::clientKeyFile(),
        );
    }

    private static function requireMode(): void
    {
        if ((string) (getenv('NATS_JS_CLUSTER_TLS') ?: '0') !== '1') {
            self::markTestSkipped('JetStream cluster TLS integration is disabled. Run with NATS_JS_CLUSTER_TLS=1.');
        }
    }

    private static function caFile(): string
    {
        return dirname(__DIR__) . '/Support/tls/cluster/ca.pem';
    }

    private static function clientCertFile(): string
    {
        return dirname(__DIR__) . '/Support/tls/cluster/client.pem';
    }

    private static function clientKeyFile(): string
    {
        return dirname(__DIR__) . '/Support/tls/cluster/client-key.pem';
    }
}
