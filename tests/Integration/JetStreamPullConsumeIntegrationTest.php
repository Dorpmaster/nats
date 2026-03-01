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
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamSlowConsumerException;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\SlowConsumerPolicy;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use PHPUnit\Framework\TestCase;

final class JetStreamPullConsumeIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::requireJetStreamMode();
        NatsServerHarness::waitUntilReady();
    }

    public function testConsumeLoopBasicFlow(): void
    {
        self::requireJetStreamMode();

        // Arrange
        $client = $this->createClient();
        $client->connect();

        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $publisher = new JetStreamPublisher($client);
        $factory   = new JetStreamPullConsumerFactory($transport);

        $stream   = 'ORDERS_CONSUME';
        $consumer = 'C1';

        $admin->createStream(new StreamConfig($stream, ['orders.consume.*']));
        $admin->createOrUpdateConsumer($stream, new ConsumerConfig($consumer, filterSubject: 'orders.consume.created'));

        for ($index = 1; $index <= 10; $index++) {
            $publisher->publish('orders.consume.created', 'msg-' . $index);
        }

        $handle = null;
        try {
            // Act
            $pullConsumer = $factory->create($stream, $consumer);
            $handle       = $pullConsumer->consume(new PullConsumeOptions(batch: 5, expiresMs: 200, noWait: false));
            $acker        = $handle->getAcker();
            $messages     = [];

            for ($i = 0; $i < 10; $i++) {
                $message = $handle->next(2000);
                self::assertNotNull($message);
                $messages[] = $message->getPayload();
                $acker->ack($message);
            }

            $handle->stop();
            $none = $handle->next(500);

            // Assert
            self::assertCount(10, $messages);
            self::assertSame('msg-1', $messages[0]);
            self::assertSame('msg-10', $messages[9]);
            self::assertNull($none);
        } finally {
            if ($handle !== null) {
                $handle->stop();
                $handle->awaitStopped(2000);
            }
            $client->disconnect();
        }
    }

    public function testConsumeBackpressureError(): void
    {
        self::requireJetStreamMode();

        // Arrange
        $client = $this->createClient();
        $client->connect();

        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $publisher = new JetStreamPublisher($client);
        $factory   = new JetStreamPullConsumerFactory($transport);

        $stream   = 'ORDERS_CONSUME_BP';
        $consumer = 'C1';

        $admin->createStream(new StreamConfig($stream, ['orders.consume.bp.*']));
        $admin->createOrUpdateConsumer($stream, new ConsumerConfig($consumer, filterSubject: 'orders.consume.bp.created'));

        for ($index = 1; $index <= 3; $index++) {
            $publisher->publish('orders.consume.bp.created', 'msg-' . $index);
        }

        $handle = null;
        try {
            // Act
            $pullConsumer = $factory->create($stream, $consumer);
            $handle       = $pullConsumer->consume(new PullConsumeOptions(
                batch: 2,
                expiresMs: 500,
                noWait: false,
                maxInFlightMessages: 1,
                maxInFlightBytes: 1024,
                policy: SlowConsumerPolicy::ERROR,
            ));

            $first = $handle->next(2000);

            // Assert
            self::assertNotNull($first);
            self::expectException(JetStreamSlowConsumerException::class);
            $handle->next(2000);
        } finally {
            if ($handle !== null) {
                $handle->stop();
                $handle->awaitStopped(2000);
            }
            $client->disconnect();
        }
    }

    public function testConsumeDrain(): void
    {
        self::requireJetStreamMode();

        // Arrange
        $client = $this->createClient();
        $client->connect();

        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $publisher = new JetStreamPublisher($client);
        $factory   = new JetStreamPullConsumerFactory($transport);

        $stream   = 'ORDERS_CONSUME_DRAIN';
        $consumer = 'C1';

        $admin->createStream(new StreamConfig($stream, ['orders.consume.drain.*']));
        $admin->createOrUpdateConsumer($stream, new ConsumerConfig($consumer, filterSubject: 'orders.consume.drain.created'));

        for ($index = 1; $index <= 5; $index++) {
            $publisher->publish('orders.consume.drain.created', 'msg-' . $index);
        }

        $handle = null;
        try {
            // Act
            $pullConsumer = $factory->create($stream, $consumer);
            $handle       = $pullConsumer->consume(new PullConsumeOptions(batch: 1, expiresMs: 300, noWait: false));
            $acker        = $handle->getAcker();
            $first        = $handle->next(2000);
            $second       = $handle->next(2000);
            self::assertNotNull($first);
            self::assertNotNull($second);
            $acker->ack($first);
            $acker->ack($second);

            $handle->drain(2000);
            $none = $handle->next(500);

            // Assert
            self::assertNull($none);
        } finally {
            if ($handle !== null) {
                $handle->stop();
                $handle->awaitStopped(2000);
            }
            $client->disconnect();
        }
    }

    private function createClient(): Client
    {
        $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

        $connector = new RetrySocketConnector(new DnsSocketConnector());
        $config    = new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port());

        $connection          = new Connection($connector, $config);
        $clientConfiguration = new ClientConfiguration();
        $eventDispatcher     = new EventDispatcher();

        $connectionInfo    = new ConnectInfo(
            verbose: false,
            pedantic: false,
            tls_required: false,
            lang: 'php',
            version: PHP_VERSION,
            protocol: 1,
            headers: true,
        );
        $storage           = new SubscriptionStorage();
        $messageDispatcher = new MessageDispatcher($connectionInfo, $storage);

        return new Client(
            configuration: $clientConfiguration,
            cancellation: $cancellation,
            connection: $connection,
            eventDispatcher: $eventDispatcher,
            messageDispatcher: $messageDispatcher,
            storage: $storage,
        );
    }

    private static function requireJetStreamMode(): void
    {
        if ((string) (getenv('NATS_JS') ?: '0') !== '1') {
            self::markTestSkipped('JetStream integration is disabled. Run with NATS_JS=1.');
        }
    }
}
