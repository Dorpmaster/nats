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
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use PHPUnit\Framework\TestCase;

final class JetStreamPublishIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::requireJetStreamMode();
        NatsServerHarness::waitUntilReady();
    }

    public function testPublishReturnsPubAckAndSupportsDeduplication(): void
    {
        self::requireJetStreamMode();

        // Arrange
        $client = $this->createClient();
        $stream = 'ORDERS';

        $client->connect();

        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $publisher = new JetStreamPublisher($client);

        $admin->createStream(new StreamConfig($stream, ['orders.*']));

        try {
            // Act
            $firstAck  = $publisher->publish(
                'orders.created',
                'hello',
                PublishOptions::create(msgId: 'dedup-1'),
            );
            $secondAck = $publisher->publish(
                'orders.created',
                'hello-again',
                PublishOptions::create(msgId: 'dedup-1'),
            );

            // Assert
            self::assertSame('ORDERS', $firstAck->getStream());
            self::assertGreaterThanOrEqual(1, $firstAck->getSeq());
            self::assertFalse($firstAck->isDuplicate());

            self::assertSame('ORDERS', $secondAck->getStream());
            self::assertTrue($secondAck->isDuplicate());
        } finally {
            $admin->deleteStream($stream);
            $client->disconnect();
        }
    }

    public function testPublishThrowsOnExpectedStreamMismatch(): void
    {
        self::requireJetStreamMode();

        // Arrange
        $client = $this->createClient();
        $stream = 'ORDERS_NEG';

        $client->connect();

        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $publisher = new JetStreamPublisher($client);

        $admin->createStream(new StreamConfig($stream, ['orders.neg.*']));

        try {
            // Assert
            self::expectException(JetStreamApiException::class);
            self::expectExceptionMessageMatches('/expected|stream|wrong/i');

            // Act
            $publisher->publish(
                'orders.neg.created',
                'payload',
                PublishOptions::create(expectedStream: 'OTHER'),
            );
        } finally {
            $admin->deleteStream($stream);
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
