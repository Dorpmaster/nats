<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\DeferredFuture;
use Amp\SignalCancellation;
use Amp\TimeoutCancellation;
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
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamAdminIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::requireJetStreamMode();
        NatsServerHarness::waitUntilReady();
    }

    public function testJetStreamAdminCrudLifecycle(): void
    {
        self::requireJetStreamMode();

        // Arrange
        $client = $this->createClient();
        $stream = 'ORDERS';

        $client->connect();

        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);

        try {
            // Act
            $createdStream = $admin->createStream(new StreamConfig($stream, ['orders.*']));
            $streamInfo    = $admin->getStreamInfo($stream);

            $createdConsumer = $admin->createOrUpdateConsumer($stream, new ConsumerConfig('C1'));
            $consumerInfo    = $admin->getConsumerInfo($stream, 'C1');

            $admin->deleteConsumer($stream, 'C1');
            $admin->deleteStream($stream);

            // Assert
            self::assertSame('ORDERS', $createdStream->getName());
            self::assertSame(['orders.*'], $createdStream->getSubjects());
            self::assertSame('ORDERS', $streamInfo->getName());
            self::assertSame('C1', $createdConsumer->getDurableName());
            self::assertSame('C1', $consumerInfo->getDurableName());
        } finally {
            $client->disconnect();
        }
    }

    #[DataProvider('extendedResourceNames')]
    public function testJetStreamAdminCrudWithExtendedResourceNames(string $stream): void
    {
        self::requireJetStreamMode();
        $client   = $this->createClient();
        $admin    = new JetStreamAdmin(new JetStreamControlPlaneTransport($client));
        $consumer = 'WORKER@EU';
        $created  = false;

        try {
            $client->connect();
            $createdStream = $admin->createStream(new StreamConfig($stream, ['compat.' . bin2hex(random_bytes(6))]));
            $created       = true;
            self::assertSame($stream, $createdStream->getName());
            self::assertSame($stream, $admin->getStreamInfo($stream)->getName());
            self::assertSame($consumer, $admin->createOrUpdateConsumer($stream, new ConsumerConfig($consumer))->getDurableName());
            self::assertSame($consumer, $admin->getConsumerInfo($stream, $consumer)->getDurableName());

            $admin->deleteConsumer($stream, $consumer);
            try {
                $admin->getConsumerInfo($stream, $consumer);
                self::fail('Deleted consumer must not exist');
            } catch (JetStreamApiException $exception) {
                self::assertSame(404, $exception->getCode());
            }
            $admin->deleteStream($stream);
            $created = false;
            try {
                $admin->getStreamInfo($stream);
                self::fail('Deleted stream must not exist');
            } catch (JetStreamApiException $exception) {
                self::assertSame(404, $exception->getCode());
            }
        } finally {
            try {
                if ($created) {
                    $admin->deleteStream($stream);
                }
            } finally {
                $client->disconnect();
            }
        }
    }

    public static function extendedResourceNames(): iterable
    {
        yield 'at-sign' => ['ORDERS@EU'];
        yield 'plus-sign' => ['ORDERS+EU'];
    }

    public function testGetStreamInfoRejectsCommandInjectionAndKeepsConnectionUsable(): void
    {
        self::requireJetStreamMode();

        $client         = $this->createClient();
        $observer       = $this->createClient();
        $admin          = new JetStreamAdmin(new JetStreamControlPlaneTransport($client));
        $token          = bin2hex(random_bytes(6));
        $stream         = 'AUDIT_' . strtoupper($token);
        $subject        = 'audit.injection.' . $token;
        $barrierSubject = 'audit.barrier.' . $token;
        $probeSubject   = 'audit.probe.' . $token;
        $injected       = [];
        $barrier        = new DeferredFuture();
        $created        = false;

        try {
            $client->connect();
            $observer->connect();
            $observer->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$injected): null {
                $injected[] = $message->getPayload();
                return null;
            });
            $observer->subscribe($barrierSubject, static function (NatsProtocolMessageInterface $message) use ($barrier): null {
                if (!$barrier->isComplete()) {
                    $barrier->complete($message->getPayload());
                }
                return null;
            });
            $observer->subscribe($probeSubject, static fn (NatsProtocolMessageInterface $message): PubMessage => new PubMessage((string) $message->getReplyTo(), 'ready'));

            // This roundtrip confirms that the server processed all observer SUBs above.
            self::assertSame('ready', $observer->request(new PubMessage($probeSubject, 'probe'), 2)->getPayload());
            $admin->createStream(new StreamConfig($stream, ['audit.data.' . $token]));
            $created = true;

            // Before validation this forms three valid PUB commands, including the injected one.
            $malicious = $stream . " INJECTREPLY 0\r\n\r\nPUB " . $subject . " 8\r\ninjected\r\nPUB " . $subject;
            try {
                $admin->getStreamInfo($malicious);
                self::fail('Expected a local InvalidArgumentException for the stream name');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('stream', $exception->getMessage());
            }

            // A successful API reply proves the same connection remains usable.
            self::assertSame($stream, $admin->getStreamInfo($stream)->getName());

            // The marker follows any injected PUB on the same connection. Waiting for it,
            // rather than for a timeout, proves that the observer processed preceding delivery.
            $client->publish(new PubMessage($barrierSubject, 'complete'));
            self::assertSame('complete', $barrier->getFuture()->await(new TimeoutCancellation(2)));
            self::assertSame([], $injected, 'The observer must not receive an injected PUB');
        } finally {
            try {
                if ($created) {
                    $admin->deleteStream($stream);
                }
            } finally {
                $observer->disconnect();
                $client->disconnect();
            }
        }
    }

    public static function invalidInfoSubjects(): iterable
    {
        yield 'null' => ['null'];
        yield 'unquoted big integer' => ['[9223372036854775808]'];
        yield 'mixed list with unquoted UInt64' => ['["orders.*",18446744073709551615]'];
    }

    #[DataProvider('invalidInfoSubjects')]
    public function testUpdateRejectsInvalidSubjectsAndKeepsRealStreamRouting(string $subjectsJson): void
    {
        self::requireJetStreamMode();
        $client = $this->createClient();
        $client->connect();
        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $stream    = 'N2_ADMIN_' . bin2hex(random_bytes(5));
        $subjects  = ['orders.*'];
        $updates   = [];
        $created   = false;
        try {
            $before     = $admin->createStream(new StreamConfig($stream, $subjects, 'memory'));
            $created    = true;
            $connection = $client->getServerInformation();
            $guarded    = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
            $guarded->expects(self::once())->method('requestJson')->willReturnCallback(static function (string $suffix, JsonObject $payload, int|null $timeoutMs) use ($transport, $subjectsJson): JsonObject {
                $info          = $transport->requestJson($suffix, $payload, $timeoutMs);
                $configuration = $info->getObject('config')->withFields(['subjects' => null])->toJson();
                // Inject original JSON tokens: PHP numeric conversion would hide this regression.
                $configuration = str_replace('"subjects":null', '"subjects":' . $subjectsJson, $configuration);
                return new JsonObject('{"config":' . $configuration . '}');
            });
            $guarded->method('requestJsonOnce')->willReturnCallback(static function (string $suffix, JsonObject $payload, int|null $timeoutMs) use ($transport, &$updates): JsonObject {
                $updates[] = $payload->toJson();
                return $transport->requestJsonOnce($suffix, $payload, $timeoutMs);
            });
            try {
                (new JetStreamAdmin($guarded))->updateStream($stream, new StreamConfigPatch(maxConsumers: 7));
                self::fail('Invalid INFO must fail before a modifying request');
            } catch (JetStreamApiException $error) {
                self::assertStringContainsString('subjects', $error->getMessage());
            }
            self::assertSame([], $updates, 'Zero UPDATE requests may reach the real transport');
            $after = $admin->getStreamInfo($stream);
            self::assertSame($subjects, $after->getSubjects());
            self::assertSame($before->getConfiguration()->get('max_consumers'), $after->getConfiguration()->get('max_consumers'));

            // A normal update and a following INFO still work through the same connection.
            $updated = $admin->updateStream($stream, new StreamConfigPatch(maxConsumers: 7));
            self::assertSame(7, $updated->getConfiguration()->get('max_consumers'));
            self::assertSame($subjects, $admin->getStreamInfo($stream)->getSubjects());
            self::assertSame($connection->connectionId, $client->getServerInformation()->connectionId);
            self::assertSame($connection->serverId, $client->getServerInformation()->serverId);
        } finally {
            try {
                if ($created) {
                    $admin->deleteStream($stream);
                }
            } finally {
                $client->disconnect();
            }
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

        $connectionInfo    = new ConnectInfo(false, false, false, 'php', PHP_VERSION);
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
