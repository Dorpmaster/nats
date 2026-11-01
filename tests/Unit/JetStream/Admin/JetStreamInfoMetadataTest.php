<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Admin;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamJsonTransportInterface;
use Dorpmaster\Nats\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamInfoMetadataTest extends TestCase
{
    public function testStreamRichSnapshotRetainsNumbersUnknownFieldsAndNestedImmutability(): void
    {
        $json   = '{"config":{"name":"S","subjects":["s.*"],"metadata":{"team":"EU","future":{"big":18446744073709551615}},"unknown":1.20e+8},"created":"2024-01-01T00:00:00.123456789Z","ts":"2024-01-01T00:00:01.987654321Z","domain":"EU","state":{"messages":18446744073709551615,"bytes":9223372036854775808,"first_seq":0,"last_seq":18446744073709551615,"first_ts":"0001-01-01T00:00:00Z","last_ts":"2024-01-01T00:00:00.123456789Z"},"cluster":{"name":"C","raft_group":"R","leader":"n1","replicas":[{"name":"n2","peer":"p2","current":true,"active":9223372036854775807,"lag":18446744073709551615,"unknown":{"description":"a,]b"}}],"desired":{"created":"2024-01-01T00:00:00Z","name":"C2","replicas":[{"name":"n3","peer":"p3"}],"origin":{"replicas":3,"placement":{"cluster":"C","tags":["EU"]},"retention":"limits"},"status":{"type":"future-status","description":"completed-looking text","err":"synthetic"}}},"future":{"k":{},"a":[],"n":18446744073709551615}}';
        $logger = new RecordingLogger();
        $admin  = $this->admin($json, $logger);
        $info   = $admin->getStreamInfo('S');
        self::assertSame($json, $info->getResponse()->toJson());
        self::assertSame('EU', $info->getDomain());
        self::assertSame('123456789', substr($info->getCreated()->getRfc3339(), -10, 9));
        self::assertSame('987654321', substr($info->getTimestamp()->getRfc3339(), -10, 9));
        self::assertSame('18446744073709551615', $info->getState()->messages);
        self::assertSame('9223372036854775808', $info->getState()->bytes);
        self::assertSame('0', $info->getState()->firstSequence);
        self::assertSame('18446744073709551615', $info->getState()->lastSequence);
        self::assertSame('1.20e+8', $info->getConfiguration()->getRawValue('unknown'));
        self::assertSame('18446744073709551615', $info->getCluster()->replicas[0]->lag);
        self::assertSame('9223372036854775807', $info->getCluster()->replicas[0]->activeNanoseconds);
        $desired = $info->getCluster()->desired;
        self::assertSame('n3', $desired->replicas[0]->name);
        self::assertNull($desired->replicas[0]->offline);
        self::assertSame('3', $desired->origin->replicas);
        self::assertSame('future-status', $desired->status->type);
        self::assertSame('synthetic', $desired->status->error);
        $copy      = $info->getMetadata()->get('future');
        $copy->big = 'changed';
        self::assertSame('18446744073709551615', $info->getMetadata()->get('future')->big);
        $copy   = $desired->origin->placement->get('tags');
        $copy[] = 'changed';
        self::assertSame(['EU'], $desired->origin->placement->get('tags'));
        self::assertStringNotContainsString('completed-looking', serialize($logger));
        self::assertStringNotContainsString('18446744073709551615', serialize($logger));
    }

    public function testConsumerRawConfigurationExactCountsAndLegacyIntegerView(): void
    {
        $json = '{"stream_name":"S","name":"C","config":{"durable_name":"C","metadata":{"x":"y"},"future":9223372036854775808},"created":"2024-01-01T00:00:00Z","ts":"2024-01-01T00:00:01Z","num_pending":18446744073709551615,"num_ack_pending":9223372036854775807,"num_redelivered":0,"num_waiting":4,"delivered":{"consumer_seq":18446744073709551615,"stream_seq":9223372036854775808,"last_active":"2024-01-01T00:00:00.000000001Z"},"ack_floor":{"consumer_seq":0,"stream_seq":0},"cluster":{"replicas":[]},"extra":{}}';
        $info = $this->admin($json)->getConsumerInfo('S', 'C');
        self::assertSame($json, $info->getResponse()->toJson());
        self::assertSame('S', $info->getStreamName());
        self::assertSame('C', $info->getName());
        self::assertSame('C', $info->getDurableName());
        self::assertSame('18446744073709551615', $info->getPendingCount());
        self::assertNull($info->getNumPending());
        self::assertSame(PHP_INT_MAX, $info->getNumAckPending());
        self::assertSame((string) PHP_INT_MAX, $info->getAckPendingCount());
        self::assertSame('0', $info->getRedeliveredCount());
        self::assertSame('4', $info->getWaitingCount());
        self::assertSame('0', $info->getAckFloor()->consumerSequence);
        self::assertSame('18446744073709551615', $info->getDelivered()->consumerSequence);
        self::assertSame('9223372036854775808', $info->getDelivered()->streamSequence);
        self::assertSame('9223372036854775808', $info->getConfiguration()->getRawValue('future'));
        self::assertSame('y', $info->getMetadata()->get('x'));
        self::assertSame([], $info->getCluster()->replicas);
        self::assertNull($info->getCluster()->desired);
    }

    public function testMissingNullZeroAndOldNamedConstructors(): void
    {
        $stream   = new StreamInfo(name: 'S', subjects: ['s']);
        $consumer = new ConsumerInfo(durableName: 'C', numPending: 1, numAckPending: 2);
        self::assertSame(['s'], $stream->getSubjects());
        self::assertNull($stream->getCreated());
        self::assertNull($stream->getState());
        self::assertNull($consumer->getConfiguration());
        self::assertSame(1, $consumer->getNumPending());
        self::assertSame(2, $consumer->getNumAckPending());
        $info = $this->admin('{"name":"C","num_pending":0,"num_waiting":null}')->getConsumerInfo('S', 'C');
        self::assertSame('0', $info->getPendingCount());
        self::assertSame(0, $info->getNumPending());
        self::assertNull($info->getAckPendingCount());
        self::assertNull($info->getWaitingCount());
        self::assertNull($info->getCluster());
    }

    public static function malformed(): iterable
    {
        foreach (['"3"', '-1', '1.0', '1e2', 'true', '{}', '[]', '18446744073709551616'] as $bad) {
            yield ['{"config":{"name":"S"},"state":{"messages":' . $bad . '}}', false, 'messages'];
            yield ['{"name":"C","num_pending":' . $bad . '}', true, 'num_pending'];
        }
        yield ['{"name":"C","num_ack_pending":9223372036854775808}', true, 'num_ack_pending'];
        yield ['{"name":"C","delivered":{"stream_seq":-1}}', true, 'stream_seq'];
        yield ['{"name":"C","cluster":{"replicas":[{"current":1}]}}', true, 'current'];
        yield ['{"name":"C","cluster":{"replicas":[1]}}', true, 'replicas'];
        yield ['{"name":"C","cluster":{"desired":{"replicas":{}}}}', true, 'replicas'];
        yield ['{"name":"C","cluster":{"desired":{"origin":{"replicas":-1}}}}', true, 'replicas'];
        yield ['{"name":"C","cluster":{"desired":{"status":{"type":1}}}}', true, 'type'];
        yield ['{"name":"C","created":"2023-02-29T00:00:00Z"}', true, 'created'];
        yield ['{"name":"C","config":{"metadata":[]}}', true, 'metadata'];
        yield ['{"config":{"name":"S"},"cluster":[]}', false, 'cluster'];
    }

    #[DataProvider('malformed')]
    public function testMalformedKnownFieldsAreControlled(string $json, bool $consumer, string $field): void
    {
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage($field);
        $admin = $this->admin($json);
        $consumer ? $admin->getConsumerInfo('S', 'C') : $admin->getStreamInfo('S');
    }

    private function admin(string $json, RecordingLogger|null $logger = null): JetStreamAdmin
    {
        $transport = $this->createMock(JetStreamJsonTransportInterface::class);
        $transport->expects(self::once())->method('requestJson')->willReturn(new JsonObject($json));
        $transport->expects(self::never())->method('request');
        return new JetStreamAdmin($transport, $logger);
    }
}
