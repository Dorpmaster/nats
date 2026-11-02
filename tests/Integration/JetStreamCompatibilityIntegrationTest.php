<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\NullCancellation;
use Amp\Socket\DnsSocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Capability\Support;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamConfigurationUnconfirmedException;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\CapturedProtocolLogs;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamCompatibilityIntegrationTest extends TestCase
{
    use AsyncTestTools;

    private CapturedProtocolLogs $logs;

    public static function prefixes(): iterable
    {
        yield 'standard' => ['$JS.API', false];
        yield 'standard trailing dot' => ['$JS.API.', false];
        yield 'real domain' => ['$JS.EU.API', false];
        yield 'real domain trailing dot' => ['$JS.EU.API.', false];
        yield 'real account import' => ['IMPORT@EU+API', true];
        yield 'real import trailing dot' => ['IMPORT@EU+API.', true];
    }

    #[DataProvider('prefixes')]
    public function testRealPrefixRoutesAdminAndPullWithoutChangingPublishSubject(string $prefix, bool $imported): void
    {
        $this->requireCompatibility();
        $this->setTimeout(15);
        $this->runAsyncTest(function () use ($prefix, $imported): void {
            $client          = $this->client($imported);
            $publisherClient = $imported ? $this->client() : $client;
            $stream          = 'N1_' . bin2hex(random_bytes(6));
            $subject         = 'n1.' . strtolower($stream);
            $transport       = new JetStreamControlPlaneTransport($client, logger: $this->logs, apiPrefix: $prefix);
            $admin           = new JetStreamAdmin($transport, $this->logs);
            try {
                $client->connect();
                if ($imported) {
                    $publisherClient->connect();
                }
                $created = $admin->createStream(new StreamConfig($stream, [$subject], 'memory'));
                self::assertSame($stream, $created->getName());
                self::assertSame([$subject], $admin->getStreamInfo($stream)->getSubjects());
                if (str_starts_with($prefix, '$JS.API')) {
                    self::assertSame(Support::SUPPORTED, $transport->getCapabilities()->check(Feature::ATOMIC_PUBLISH)->support);
                } else {
                    self::assertSame(Support::UNKNOWN, $transport->getCapabilities()->check(Feature::ATOMIC_PUBLISH)->support);
                }
                $admin->createOrUpdateConsumer($stream, new ConsumerConfig('C1'));
                $publisher = new JetStreamPublisher($publisherClient, $this->logs);
                $consumer  = new JetStreamPullConsumer($transport, $stream, 'C1', logger: $this->logs);
                foreach (['one', 'two'] as $value) {
                    $payload = "synthetic-n1-payload-secret\r\n\0" . $value;
                    $publisher->publish($subject, $payload);
                    $messages = iterator_to_array($consumer->fetch(1, 1000, true)->messages());
                    self::assertCount(1, $messages);
                    self::assertSame($payload, $messages[0]->getPayload());
                }
                self::assertSame($stream, $admin->getStreamInfo($stream)->getName());
            } finally {
                try {
                    $admin->deleteStream($stream);
                } finally {
                    $client->disconnect();
                    $publisherClient->disconnect();
                    $this->forceTick();
                    $this->logs->assertSafe(['synthetic-n1-password', 'synthetic-n1-payload-secret']);
                }
            }
        });
    }

    public function testUpdatePreservesNestedConfigurationAndServerSemantics(): void
    {
        $this->requireCompatibility();
        $this->setTimeout(15);
        $this->runAsyncTest(function (): void {
            $client    = $this->client();
            $stream    = 'N1_' . bin2hex(random_bytes(6));
            $subject   = 'n1.' . strtolower($stream);
            $transport = new JetStreamControlPlaneTransport($client, logger: $this->logs);
            $admin     = new JetStreamAdmin($transport, $this->logs);
            try {
                $client->connect();
                // Setup raw known server settings which the driver does not model as CREATE options.
                $transport->requestJson('STREAM.CREATE.' . $stream, JsonObject::fromArray([
                    'name' => $stream,
                    'subjects' => [$subject],
                    'storage' => 'memory',
                    'retention' => 'limits',
                    'max_msgs' => 1234,
                    'max_bytes' => 10485760,
                    'max_age' => 9007199254740993,
                    'max_msgs_per_subject' => 17,
                    'discard' => 'new',
                    'metadata' => ['tenant' => 'synthetic-n1-metadata-secret', 'nested-note' => 'kept'],
                    'consumer_limits' => ['inactive_threshold' => 60000000000, 'max_ack_pending' => 25],
                ]));
                $before  = $admin->getStreamInfo($stream)->getConfiguration();
                $updated = $admin->updateStream($stream, new StreamConfigPatch(subjects: [$subject, $subject . '.new'], replicas: 1, allowAtomicPublish: true, maxConsumers: 7), 2000);
                self::assertSame([$subject, $subject . '.new'], $updated->getSubjects());
                self::assertTrue($updated->getConfiguration()->get('allow_atomic'));
                self::assertSame(7, $updated->getConfiguration()->get('max_consumers'));
                foreach (['storage', 'retention', 'max_msgs', 'max_bytes', 'max_age', 'max_msgs_per_subject', 'discard', 'consumer_limits'] as $field) {
                    self::assertEquals($before->get($field), $updated->getConfiguration()->get($field), $field);
                }
                self::assertSame('synthetic-n1-metadata-secret', $updated->getConfiguration()->get('metadata')->tenant);
                self::assertSame(9007199254740993, $updated->getConfiguration()->get('max_age'));
                $unlimited = $admin->updateStream($stream, new StreamConfigPatch(maxConsumers: 0));
                self::assertSame(-1, $unlimited->getConfiguration()->get('max_consumers'));
                $disabled = $admin->updateStream($stream, new StreamConfigPatch(allowAtomicPublish: false, allowBatchPublish: $client->getServerInformation()->apiLevel >= 4 ? false : null));
                self::assertFalse($disabled->getConfiguration()->get('allow_atomic') ?? false);
                self::assertFalse($disabled->getConfiguration()->get('allow_batched') ?? false);
                self::assertEquals($disabled->getConfiguration()->get('consumer_limits'), $admin->getStreamInfo($stream)->getConfiguration()->get('consumer_limits'));
            } finally {
                try {
                    $admin->deleteStream($stream);
                } finally {
                    $client->disconnect();
                    $this->forceTick();
                    $this->logs->assertSafe(['synthetic-n1-password', 'synthetic-n1-metadata-secret']);
                }
            }
        });
    }

    public function testApiLevelsUnsupportedSettingsAndDistinctNotFoundCode(): void
    {
        $this->requireCompatibility();
        $this->setTimeout(15);
        $this->runAsyncTest(function (): void {
            $client    = $this->client();
            $stream    = 'N1_' . bin2hex(random_bytes(6));
            $transport = new JetStreamControlPlaneTransport($client, logger: $this->logs);
            $admin     = new JetStreamAdmin($transport, $this->logs);
            try {
                $client->connect();
                $admin->createStream(new StreamConfig($stream, ['n1.' . strtolower($stream)], 'memory'));
                $capabilities = $transport->getCapabilities();
                $version      = getenv('NATS_EXPECTED_VERSION');
                self::assertSame($version, $capabilities->server->version);
                $level = match ($version) {
                    '2.12.6' => 3, '2.14.0' => 4, '2.15.2' => 5
                };
                self::assertSame($level, $capabilities->server->apiLevel);
                // Fixed-version expectations must not be derived from the production threshold table.
                $later = $version === '2.12.6' ? Support::UNSUPPORTED : Support::SUPPORTED;
                foreach (
                    [
                    [Feature::ATOMIC_PUBLISH, Support::SUPPORTED],
                    [Feature::CONSUMER_RESET, $later],
                    [Feature::ATOMIC_EOB, $later],
                    [Feature::FAST_BATCH_PUBLISH, $later],
                    ] as [$feature, $expected]
                ) {
                    self::assertSame($expected, $capabilities->check($feature)->support);
                }
                try {
                    $result = $admin->updateStream($stream, new StreamConfigPatch(allowBatchPublish: true));
                    self::assertGreaterThanOrEqual(4, $level, 'Old server must not report enabling an ignored field');
                    self::assertTrue($result->getConfiguration()->get('allow_batched'));
                } catch (JetStreamApiException $error) {
                    self::assertSame(3, $level);
                    if ($error instanceof JetStreamConfigurationUnconfirmedException) {
                        self::assertSame(['allow_batched'], $error->getFields());
                    } else {
                        self::assertSame(400, $error->getApiCode());
                        self::assertSame(10025, $error->getErrorCode());
                    }
                }
                try {
                    $admin->updateStream('ABSENT_' . $stream, new StreamConfigPatch(replicas: 1));
                    self::fail('Missing stream must not be created');
                } catch (JetStreamApiException $error) {
                    self::assertSame(404, $error->getApiCode());
                    self::assertSame(10059, $error->getErrorCode());
                }
                self::assertSame($stream, $admin->getStreamInfo($stream)->getName());
            } finally {
                try {
                    $admin->deleteStream($stream);
                } finally {
                    $client->disconnect();
                    $this->forceTick();
                    $this->logs->assertSafe(['synthetic-n1-password']);
                }
            }
        });
    }

    private function requireCompatibility(): void
    {
        if (getenv('NATS_JS_COMPATIBILITY') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-compatibility for the pinned server/prefix matrix.');
        }
        $this->logs = new CapturedProtocolLogs();
    }

    private function client(bool $imported = false): Client
    {
        $storage = new SubscriptionStorage();
        return new Client(
            configuration: new ClientConfiguration(),
            cancellation: new NullCancellation(),
            connection: new Connection(new DnsSocketConnector(), new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port()), $this->logs),
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, user: $imported ? 'importer' : null, pass: $imported ? 'synthetic-n1-password' : null, headers: true, protocol: 1), $storage, $this->logs),
            storage: $storage,
            logger: $this->logs,
        );
    }
}
