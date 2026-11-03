<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Model\{ConsumerConfig,JsonObject,StreamConfig,StreamConfigPatch};
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Tests\Support\{AsyncTestTools,JetStreamResetFixture,RecordingStreamSourceTransport,StreamSourceFixture};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamSourcesExternalIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function cases(): iterable
    {
        foreach (['source','mirror'] as $mode) {
            foreach ([false,true] as $shadow) {
                yield [$mode,$shadow];
            }
        }
    }
    #[DataProvider('cases')]
    public function testExternalLimitUpdatePreservesRouteWithoutConsumerRevalidation(string $mode, bool $shadow): void
    {
        if (getenv('NATS_SOURCES_EXTERNAL') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-sources-external');
        }
        $this->setTimeout(35);
        $this->runAsyncTest(function () use ($mode, $shadow): void {
            $oldHost              = $_SERVER['NATS_HOST'] ?? null;
            $_SERVER['NATS_HOST'] = 'hub';
            $origin               = new StreamSourceFixture();
            $_SERVER['NATS_HOST'] = 'leaf';
            $destination          = new JetStreamResetFixture();
            $reader               = new JetStreamResetFixture();
            $transport            = new RecordingStreamSourceTransport(new JetStreamControlPlaneTransport($destination->client));
            $admin                = new JetStreamAdmin($transport);
            $field                = $mode === 'mirror' ? 'mirror' : 'sources';
            try {
                $origin->ownedConsumer();
                if ($shadow) {
                    // Same names in DEST, but an incompatible unrelated consumer. It must never be used.
                    $destination->admin->createStream(new StreamConfig($origin->origin, ['trap.' . bin2hex(random_bytes(4))]));
                    $destination->admin->createOrUpdateConsumer($origin->origin, new ConsumerConfig('OWNED', deliverSubject:'trap.delivery.' . bin2hex(random_bytes(4))));
                }
                // This is a pre-existing external link, configured through the real server API.
                $ref     = ['name' => $origin->origin,'external' => ['api' => '$JS.ORIGIN.API'],'consumer' => ['name' => 'OWNED','deliver_subject' => $origin->deliverSubject]];
                $created = $destination->transport->requestJson('STREAM.CREATE.' . $origin->target, JsonObject::fromArray(['name' => $origin->target,'storage' => 'file',$field => $mode === 'mirror' ? $ref : [$ref]]));
                $rows    = function (int $count) use ($reader, $origin): array {
                    $deadline = hrtime(true) + 10_000_000_000;
                    do {
                        $info = $reader->admin->getStreamInfo($origin->target);
                        if ((int)$info->getState()->messages >= $count) {
                            break;
                        }
                        \Amp\delay(.03);
                    } while (hrtime(true) < $deadline);
                    $out = [];
                    for ($seq = 1; $seq <= (int)$info->getState()->lastSequence; $seq++) {
                        $msg   = $reader->transport->requestJson('STREAM.MSG.GET.' . $origin->target, JsonObject::fromArray(['seq' => $seq]))->getObject('message');
                        $out[] = ['seq' => $msg->getRawValue('seq'),'subject' => $msg->get('subject'),'payload_hex' => bin2hex(base64_decode($msg->get('data') ?? '', true))];
                    }
                    return $out;
                };
                $origin->publisher->publish($origin->subject, 'before');
                self::assertSame([bin2hex('before')], array_column($rows(1), 'payload_hex'));
                $before = $reader->admin->getStreamInfo($origin->target)->getConfiguration();
                $result = $admin->updateStream($origin->target, new StreamConfigPatch(maxConsumers:7));
                self::assertSame(7, $result->getConfiguration()->get('max_consumers'));
                self::assertSame($before->getRawValue($field), $result->getConfiguration()->getRawValue($field));
                self::assertCount(2, $transport->records);
                self::assertTrue($transport->records[1]['once']);
                self::assertSame($before->getRawValue($field), (new JsonObject($transport->records[1]['body']))->getRawValue($field));
                self::assertSame([], array_filter($transport->records, static fn(array $r): bool=>str_contains($r['subject'], '.CONSUMER.')));
                $origin->publisher->publish($origin->subject, 'after');
                $copied = $rows(2);
                self::assertSame([bin2hex('before'),bin2hex('after')], array_column($copied, 'payload_hex'));
                $requests = $transport->records;
                $removed  = $admin->updateStream($origin->target, $mode === 'mirror' ? new StreamConfigPatch(mirror:false) : new StreamConfigPatch(sources:[]));
                self::assertNull($removed->getConfiguration()->get($field));
                self::assertSame('OWNED', $origin->admin->getConsumerInfo($origin->origin, 'OWNED')->getName());
                if ($shadow) {
                    self::assertSame('explicit', $destination->admin->getConsumerInfo($origin->origin, 'OWNED')->getConfiguration()->get('ack_policy'));
                }
                if ($dir = getenv('NATS_SOURCES_TRACE_DIR')) {
                    file_put_contents($dir . '/external-' . $mode . '-' . ($shadow ? 'shadow' : 'no-shadow') . '.json', json_encode(['create_response' => $created->toJson(),'before_configuration' => $before->toJson(),'update_requests' => $requests,'after_info' => $result->getResponse()->toJson(),'rows' => $copied,'remove_requests' => array_slice($transport->records, 2),'remote_consumer_preserved' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                }
            } finally {
                try {
                    $destination->admin->deleteStream($origin->target);
                } finally {
                    if ($shadow) {
                        $destination->admin->deleteStream($origin->origin);
                    }
                    $destination->close();
                    $reader->close();
                    $origin->close();
                    if ($oldHost === null) {
                        unset($_SERVER['NATS_HOST']);
                    } else {
                        $_SERVER['NATS_HOST'] = $oldHost;
                    }
                }
            }
        });
    }
}
