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
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageMetadataInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageHeadersInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\MessageMetadata;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use Dorpmaster\Nats\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamMetadataIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function deliveries(): iterable
    {
        yield 'fetch MSG' => [false, false];
        yield 'fetch HMSG' => [false, true];
        yield 'consume MSG' => [true, false];
        yield 'consume HMSG' => [true, true];
    }

    #[DataProvider('deliveries')]
    public function testDeliveryNakRedeliveryAckAndRawAdmin(bool $consume, bool $headers): void
    {
        if (getenv('NATS_METADATA') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-metadata for the pinned metadata matrix.');
        }
        $this->setTimeout(15);
        $this->runAsyncTest(function () use ($consume, $headers): void {
            $logs      = new RecordingLogger();
            $client    = self::client($logs);
            $stream    = 'N2_' . bin2hex(random_bytes(5));
            $subject   = 'n2.' . strtolower($stream);
            $payload   = "synthetic-n2-payload\r\n\0";
            $transport = new JetStreamControlPlaneTransport($client, logger: $logs);
            $admin     = new JetStreamAdmin($transport, $logs);
            $handle    = null;
            $created   = false;
            try {
                $client->connect();
                $transport->requestJson('STREAM.CREATE.' . $stream, new JsonObject('{"name":"' . $stream . '","subjects":["' . $subject . '"],"storage":"memory","metadata":{"owner":"n2"}}'));
                $created = true;
                self::assertSame(getenv('NATS_EXPECTED_VERSION'), $client->getServerInformation()->version);
                $connection = $client->getServerInformation();
                self::assertNotNull($connection->connectionId);
                $transport->requestJson('CONSUMER.CREATE.' . $stream . '.C', new JsonObject('{"stream_name":"' . $stream . '","config":{"durable_name":"C","ack_policy":"explicit","ack_wait":30000000000,"metadata":{"owner":"n2-consumer"}}}'));
                $bag = new HeaderBag();
                $bag->set('X', ['NATS/1.0', 'https://example.test/NATS/a:b']);
                $bag->set('Nats-Num-Delivered', '999');
                $client->request($headers ? new HPubMessage($subject, $payload, $bag) : new PubMessage($subject, $payload), 2);
                $pull = new JetStreamPullConsumer($transport, $stream, 'C');
                if ($consume) {
                    $handle = $pull->consume(new PullConsumeOptions(batch: 1, expiresMs: 200));
                    $first  = $handle->next(2000);
                    $acker  = $handle->getAcker();
                } else {
                    $result = $pull->fetch(1, 2000, true);
                    $first  = iterator_to_array($result->messages())[0];
                    $acker  = $result->getAcker();
                }
                self::assertNotNull($first);
                self::assertInstanceOf(JetStreamMessageMetadataInterface::class, $first);
                self::assertInstanceOf(JetStreamMessageHeadersInterface::class, $first);
                $metadata = $first->getMetadata();
                self::assertInstanceOf(MessageMetadata::class, $metadata);
                self::assertSame($stream, $metadata->stream);
                self::assertSame('C', $metadata->consumer);
                self::assertSame('1', $metadata->streamSequence);
                self::assertSame('1', $metadata->consumerSequence);
                self::assertSame('1', $metadata->deliveryCount);
                self::assertSame('0', $metadata->pending);
                self::assertSame(1, $first->getDeliveryCount());
                self::assertSame($payload, $first->getPayload());
                self::assertSame($headers ? ['X' => ['NATS/1.0', 'https://example.test/NATS/a:b'], 'Nats-Num-Delivered' => ['999']] : [], $first->getHeaderValues());
                $v2 = getenv('NATS_ACK_V2') === '1';
                self::assertSame($v2 ? (getenv('NATS_METADATA_DOMAIN') ?: '') : null, $metadata->domain);
                $v2 ? self::assertNotEmpty($metadata->accountHash) : self::assertNull($metadata->accountHash);
                $acker->nak($first);
                if ($consume) {
                    $second = $handle->next(2000);
                } else {
                    $result = $pull->fetch(1, 2000, false);
                    $second = iterator_to_array($result->messages())[0];
                    $acker  = $result->getAcker();
                }
                self::assertNotNull($second);
                self::assertSame($payload, $second->getPayload());
                self::assertSame($first->getHeaderValues(), $second->getHeaderValues());
                self::assertSame('2', $second->getMetadata()->deliveryCount);
                self::assertSame(2, $second->getDeliveryCount());
                self::assertSame('2', $second->getMetadata()->consumerSequence);
                self::assertSame($metadata->streamSequence, $second->getMetadata()->streamSequence);
                self::assertSame($metadata->timestamp->getUnixNanoseconds(), $second->getMetadata()->timestamp->getUnixNanoseconds());
                $acker->ack($second);
                if ($handle !== null) {
                    $handle->stop();
                    $handle->awaitStopped(2000);
                    $handle = null;
                }
                // ACK and these requests share the same connection; no reconnect or sleep barrier.
                $streamInfo   = $admin->getStreamInfo($stream);
                $consumerInfo = $admin->getConsumerInfo($stream, 'C');
                self::assertSame('1', $streamInfo->getState()->messages);
                self::assertSame('2', $consumerInfo->getAckFloor()->consumerSequence);
                self::assertSame('1', $consumerInfo->getAckFloor()->streamSequence);
                self::assertSame('0', $consumerInfo->getAckPendingCount());
                self::assertSame('0', $consumerInfo->getPendingCount());
                self::assertSame('n2', $streamInfo->getMetadata()->get('owner'));
                self::assertSame('n2-consumer', $consumerInfo->getMetadata()->get('owner'));
                $rawStream   = $streamInfo->getResponse();
                $rawConsumer = $consumerInfo->getResponse();
                foreach (['messages' => 'messages', 'bytes' => 'bytes', 'first_seq' => 'firstSequence', 'last_seq' => 'lastSequence'] as $wireField => $property) {
                    self::assertSame($rawStream->getObject('state')->getRawValue($wireField), $streamInfo->getState()->{$property});
                }
                foreach (['num_pending' => 'getPendingCount', 'num_ack_pending' => 'getAckPendingCount', 'num_redelivered' => 'getRedeliveredCount', 'num_waiting' => 'getWaitingCount'] as $wireField => $getter) {
                    self::assertSame($rawConsumer->getRawValue($wireField), $consumerInfo->{$getter}());
                }
                foreach (['created' => 'getCreated', 'ts' => 'getTimestamp'] as $wireField => $getter) {
                    self::assertSame($rawStream->get($wireField), $streamInfo->{$getter}()?->getRfc3339());
                    self::assertSame($rawConsumer->get($wireField), $consumerInfo->{$getter}()?->getRfc3339());
                }
                foreach (['delivered' => 'getDelivered', 'ack_floor' => 'getAckFloor'] as $wireField => $getter) {
                    $sequence = $consumerInfo->{$getter}();
                    self::assertSame($rawConsumer->getObject($wireField)->getRawValue('consumer_seq'), $sequence->consumerSequence);
                    self::assertSame($rawConsumer->getObject($wireField)->getRawValue('stream_seq'), $sequence->streamSequence);
                    self::assertSame($rawConsumer->getObject($wireField)->get('last_active'), $sequence->lastActive?->getRfc3339());
                }
                self::assertSame($rawStream->get('domain'), $streamInfo->getDomain());
                self::assertSame($rawConsumer->get('stream_name'), $consumerInfo->getStreamName());
                self::assertSame($rawConsumer->get('name'), $consumerInfo->getName());
                self::assertSame($rawConsumer->getRawValue('num_pending'), $consumerInfo->getPendingCount());
                self::assertSame($rawConsumer->getObject('config')->toJson(), $consumerInfo->getConfiguration()->toJson());
                self::assertSame($metadata->timestamp->getUnixNanoseconds(), $streamInfo->getState()->firstTime->getUnixNanoseconds());
                self::assertSame($metadata->timestamp->getUnixNanoseconds(), $streamInfo->getState()->lastTime->getUnixNanoseconds());
                self::save('stream-' . ($consume ? 'consume' : 'fetch') . '-' . ($headers ? 'hmsg' : 'msg'), $rawStream);
                self::save('consumer-' . ($consume ? 'consume' : 'fetch') . '-' . ($headers ? 'hmsg' : 'msg'), $rawConsumer);
                self::save('ack-' . ($consume ? 'consume' : 'fetch') . '-' . ($headers ? 'hmsg' : 'msg'), JsonObject::fromArray(['first' => $first->getReplyTo(), 'second' => $second->getReplyTo()]));
                self::assertSame($stream, $admin->getStreamInfo($stream)->getName());
                self::assertSame($connection->connectionId, $client->getServerInformation()->connectionId);
                self::assertSame($connection->serverId, $client->getServerInformation()->serverId);
                self::assertStringNotContainsString('synthetic-n2-payload', serialize($logs));
                self::assertStringNotContainsString('https://example.test/NATS/a:b', serialize($logs));
                self::assertStringNotContainsString($first->getReplyTo(), serialize($logs));
            } finally {
                if ($handle !== null) {
                    $handle->stop();
                    $handle->awaitStopped(2000);
                }
                try {
                    if ($created) {
                        $admin->deleteStream($stream);
                    }
                } finally {
                    $client->disconnect();
                }
            }
        });
    }

    public function testRealDesiredSnapshotHeldByPeerPause(): void
    {
        if (getenv('NATS_METADATA_DESIRED') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-metadata-cluster for the held replica transition.');
        }
        $this->setTimeout(15);
        $this->runAsyncTest(function (): void {
            $client = self::client(new RecordingLogger());
            try {
                $client->connect();
                $transport = new JetStreamControlPlaneTransport($client);
                $admin     = new JetStreamAdmin($transport);
                // The runner paused two new peers and an old follower: R3 retains two voters, meta retains four of seven.
                // Two independent INFO requests remain in desired state until that explicit barrier is released.
                for ($index = 0; $index < 2; $index++) {
                    $info = $admin->getStreamInfo('N2_DESIRED');
                    self::save('desired-' . $index, $info->getResponse());
                    $desired = $info->getCluster()->desired;
                    self::assertNotNull($info->getResponse()->getObject('cluster')->getObject('desired'), 'Server response omitted desired');
                    self::assertNotNull($desired);
                    $connection ??= $client->getServerInformation();
                    self::assertNotNull($desired->created);
                    self::assertSame('N2', $desired->name);
                    self::assertCount(5, $desired->replicas);
                    self::assertSame('3', $desired->origin->replicas);
                    $cluster = $info->getCluster();
                    self::assertSame('1', $info->getState()->messages);
                    self::assertSame($cluster->raw->get('leader'), $cluster->leader);
                    self::assertSame($cluster->raw->get('raft_group'), $cluster->raftGroup);
                    foreach ($cluster->replicas as $peer) {
                        self::assertSame($peer->raw->get('name'), $peer->name);
                        self::assertSame($peer->raw->get('peer'), $peer->peerId);
                        self::assertSame($peer->raw->get('current'), $peer->current);
                        self::assertSame($peer->raw->get('offline'), $peer->offline);
                        self::assertSame($peer->raw->getRawValue('lag'), $peer->lag);
                        self::assertSame($peer->raw->getRawValue('active'), $peer->activeNanoseconds);
                    }
                    self::assertSame($desired->raw->get('created'), $desired->created->getRfc3339());
                    self::assertSame($desired->raw->getObject('origin')->getObject('placement')->toJson(), $desired->origin->placement->toJson());
                    self::assertNotNull($desired->status);
                    self::assertNotEmpty($desired->status->type);
                    self::assertSame($desired->status->raw->get('type'), $desired->status->type);
                    self::assertSame($desired->status->raw->get('description'), $desired->status->description);
                    self::assertSame($desired->status->raw->get('err'), $desired->status->error);
                    foreach ($desired->replicas as $peer) {
                        self::assertNotEmpty($peer->name);
                        self::assertNotEmpty($peer->peerId);
                        self::assertSame($peer->raw->get('peer'), $peer->peerId);
                        self::assertSame($peer->raw->get('offline'), $peer->offline);
                    }
                    $raw = $info->getResponse();
                    self::assertSame($raw->getObject('cluster')->getObject('desired')->toJson(), $desired->raw->toJson());
                    self::save('desired-' . $index, $raw);
                }
                self::assertSame('N2_DESIRED', $admin->getStreamInfo('N2_DESIRED')->getName());
                self::assertSame($connection->connectionId, $client->getServerInformation()->connectionId);
                self::assertSame($connection->serverId, $client->getServerInformation()->serverId);
            } finally {
                $client->disconnect();
            }
        });
    }

    private static function client(RecordingLogger $logs): Client
    {
        $storage = new SubscriptionStorage();
        return new Client(
            configuration: new ClientConfiguration(),
            cancellation: new NullCancellation(),
            connection: new Connection(new DnsSocketConnector(), new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port()), $logs),
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, headers: true, protocol: 1), $storage, $logs),
            storage: $storage,
            logger: $logs,
        );
    }

    private static function save(string $name, JsonObject $raw): void
    {
        $directory = getenv('NATS_METADATA_TRACE_DIR');
        if ($directory !== false && $directory !== '') {
            file_put_contents($directory . '/' . $name . '.json', $raw->toJson() . "\n");
        }
    }
}
