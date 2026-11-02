<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\{ConsumerConfig,StreamConfig,StreamConfigPatch,StreamSource,StreamSourceConsumer,JsonObject};
use Dorpmaster\Nats\Tests\Support\{AsyncTestTools,StreamSourceFixture};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamSourcesIntegrationTest extends TestCase
{
    use AsyncTestTools;

    private function enabled(bool $cluster = false): void
    {
        if (getenv($cluster ? 'NATS_SOURCES_CLUSTER' : 'NATS_SOURCES') !== '1') {
            self::markTestSkipped('Run the N7 sources matrix or cluster target');
        }
        $this->setTimeout(45);
    }
    public static function modes(): iterable
    {
        yield ['source'];
        yield ['mirror'];
    }
    public static function ownershipModes(): iterable
    {
        foreach (['source','mirror'] as $mode) {
            foreach (['limits','workqueue','interest'] as $retention) {
                yield [$mode,$retention];
            }
        }
    }
    private function assertRows(array $rows, array $payloads): void
    {
        self::assertSame(array_map('bin2hex', $payloads), array_column($rows, 'payload_hex'));
    }
    #[DataProvider('modes')]
    public function testBasicServerManagedCopyAndUpdate(string $mode): void
    {
        $this->enabled();$this->runAsyncTest(function () use ($mode): void {
            $f = new StreamSourceFixture();try {
                $f->publisher->publish($f->subject, "before\r\n\0");
                $created = $f->create($mode, false);
                $f->publisher->publish($f->subject, 'after');
                $rows = $f->waitRows(2);
                $this->assertRows($rows, ["before\r\n\0",'after']);
                $before   = $f->reader->admin->getStreamInfo($f->target);
                $observed = $mode === 'mirror' ? $before->getMirror() : $before->getSources()[0];
                self::assertSame($f->origin, $observed->name);
                self::assertNull($observed->error);
                self::assertSame('0', $observed->lag);
                self::assertNotNull($observed->activeNanoseconds);
                $config = $f->admin->updateStream($f->target, new StreamConfigPatch(maxConsumers:7))->getConfiguration();
                self::assertSame($before->getConfiguration()->getRawValue($mode === 'mirror' ? 'mirror' : 'sources'), $config->getRawValue($mode === 'mirror' ? 'mirror' : 'sources'));
                $f->publisher->publish($f->subject, 'after-update');
                $this->assertRows($f->waitRows(3), ["before\r\n\0",'after','after-update']);
                if ($mode === 'mirror') {
                    try {
                        $f->admin->updateStream($f->target, new StreamConfigPatch(mirror:new StreamSource($f->origin, filterSubject:'no.match')));
                        self::fail('Existing mirror cannot be replaced');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(400, $error->getApiCode());
                        self::assertNotNull($error->getErrorCode());
                    }
                }
                $f->trace('basic-' . $mode, ['create_response' => $created->getResponse()->toJson(),'info' => $before->getResponse()->toJson(),'rows' => $f->rows()]);
            } finally {
                $f->close();
            }
        });
    }
    #[DataProvider('ownershipModes')]
    public function testProvidedDurableConsumerCopiesAndSurvivesRemoval(string $mode, string $retention): void
    {
        $this->enabled();$this->runAsyncTest(function () use ($mode, $retention): void {
            $f = new StreamSourceFixture($retention);try {
                $f->ownedConsumer();
                $f->publisher->publish($f->subject, 'before');
                $from = count($f->transport->records);
                if (getenv('NATS_EXPECTED_VERSION') === '2.12.6') {
                    try {
                        $f->create($mode, true);
                        self::fail('No durable mode on old server');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(0, $error->getApiCode());
                    }
                    self::assertSame([], $f->controls($from));
                    self::assertSame('OWNED', $f->admin->getConsumerInfo($f->origin, 'OWNED')->getName());
                    try {
                        $f->reader->admin->getStreamInfo($f->target);
                        self::fail('Target must not be created');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(404, $error->getApiCode());
                    }
                    $f->trace('old-durable-' . $mode . '-' . $retention, ['controls' => 0,'consumer' => 'preserved']);
                    return;
                }
                $created = $f->create($mode, true);
                $f->publisher->publish($f->subject, 'after');
                $this->assertRows($f->waitRows(2), ['before','after']);
                $f->admin->updateStream($f->target, new StreamConfigPatch(maxConsumers:7));
                $f->publisher->publish($f->subject, 'after-update');
                $this->assertRows($f->waitRows(3), ['before','after','after-update']);
                $info  = $f->reader->admin->getStreamInfo($f->target);
                $state = $mode === 'mirror' ? $info->getMirror() : $info->getSources()[0];
                self::assertSame('0', $state->lag);
                self::assertNull($state->error);
                $f->remove($mode);
                $owned = $f->admin->getConsumerInfo($f->origin, 'OWNED');
                self::assertSame('OWNED', $owned->getName());
                self::assertSame('flow_control', $owned->getConfiguration()->get('ack_policy'));
                self::assertSame([], array_filter($f->controls($from), static fn(array $record): bool=>str_contains($record['subject'], '.CONSUMER.DELETE.')));
                $f->trace('owned-' . $mode . '-' . $retention, ['create_response' => $created->getResponse()->toJson(),'info' => $info->getResponse()->toJson(),'rows' => $f->rows(),'consumer_after_remove' => $owned->getResponse()->toJson()]);
            } finally {
                $f->close();
            }
        });
    }
    #[DataProvider('modes')]
    public function testServerManagedVisibleConsumerLifecycle(string $mode): void
    {
        $this->enabled();$this->runAsyncTest(function () use ($mode): void {
            $f = new StreamSourceFixture('interest');try {
                // Keep pre-link messages retained independently of the server-created sourcing consumer.
                $f->admin->createOrUpdateConsumer($f->origin, new ConsumerConfig('HOLD'));
                $f->publisher->publish($f->subject, 'before');
                if (getenv('NATS_EXPECTED_VERSION') === '2.12.6') {
                    $from = count($f->transport->records);
                    try {
                        $f->create($mode, false);
                        self::fail('Server-managed Interest sourcing must fail on the old API');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(0, $error->getApiCode());
                    }
                    self::assertSame([], $f->controls($from));
                    self::assertSame('HOLD', $f->admin->getConsumerInfo($f->origin, 'HOLD')->getName());
                    $f->trace('managed-old-' . $mode, ['control_writes' => 0, 'hold_consumer' => 'preserved']);
                    return;
                }
                $f->create($mode, false);
                $f->publisher->publish($f->subject, 'after');
                $this->assertRows($f->waitRows(2), ['before','after']);
                $listed = $f->reader->transport->requestJson('CONSUMER.LIST.' . $f->origin, new JsonObject('{}'));
                $names  = [];
                foreach ($listed->get('consumers') ?? [] as $consumer) {
                    if ($consumer->name !== 'HOLD') {
                        $names[] = $consumer->name;
                    }
                }
                self::assertCount(1, $names);
                $managed = $f->admin->getConsumerInfo($f->origin, $names[0]);
                self::assertSame('flow_control', $managed->getConfiguration()->get('ack_policy'));
                $metadata = $managed->getMetadata();
                self::assertNotNull($metadata);
                self::assertSame($f->target, $metadata->get($mode === 'mirror' ? '_nats.mirror.stream' : '_nats.src.stream'));
                $f->remove($mode);
                $deadline = hrtime(true) + 8_000_000_000;
                $deleted  = false;
                do {
                    try {
                        $f->admin->getConsumerInfo($f->origin, $names[0]);
                    } catch (JetStreamApiException $error) {
                        if ($error->getApiCode() !== 404) {
                            throw $error;
                        }$deleted = true;
                        break;
                    }\Amp\delay(.05);
                } while (hrtime(true) < $deadline);
                self::assertTrue($deleted);
                self::assertSame('HOLD', $f->admin->getConsumerInfo($f->origin, 'HOLD')->getName());
                $f->trace('managed-' . $mode, ['consumer' => $managed->getResponse()->toJson(),'consumer_deleted_by_server' => $deleted,'rows' => $f->rows()]);
            } finally {
                $f->close();
            }
        });
    }
    #[DataProvider('modes')]
    public function testMissingOrWrongDeliveryConsumerHasNoFalseDelivery(string $mode): void
    {
        $this->enabled();$this->runAsyncTest(function () use ($mode): void {
            $f = new StreamSourceFixture();try {
                $f->ownedConsumer();
                $f->publisher->publish($f->subject, 'before');
                foreach (['MISSING','OWNED'] as $consumer) {
                    $src  = new StreamSource($f->origin, new StreamSourceConsumer($consumer, $f->deliverSubject . '.wrong'));
                    $from = count($f->transport->records);
                    try {
                        $f->admin->createStream(new StreamConfig($f->target, [], mirror:$mode === 'mirror' ? $src : null, sources:$mode === 'source' ? [$src] : null));
                        self::fail('Bad attachment must fail');
                    } catch (JetStreamApiException $error) {
                        if (getenv('NATS_EXPECTED_VERSION') !== '2.12.6' && $consumer === 'MISSING') {
                            self::assertSame(404, $error->getApiCode());
                            self::assertSame(10014, $error->getErrorCode());
                        }
                    }
                    self::assertSame([], $f->controls($from));
                }
                try {
                    $f->reader->admin->getStreamInfo($f->target);
                    self::fail('No successful target configuration');
                } catch (JetStreamApiException $error) {
                    self::assertSame(404, $error->getApiCode());
                }
                self::assertSame('OWNED', $f->admin->getConsumerInfo($f->origin, 'OWNED')->getName());
                $f->trace('invalid-' . $mode, ['control_writes' => 0,'target_exists' => false]);
            } finally {
                $f->close();
            }
        });
    }
    #[DataProvider('modes')]
    public function testNullInfoCannotRemoveActiveLinkDuringLimitUpdate(string $mode): void
    {
        $this->enabled();$this->runAsyncTest(function () use ($mode): void {
            $f = new StreamSourceFixture();
            try {
                if (getenv('NATS_EXPECTED_VERSION') === '2.12.6') {
                    $f->create($mode, false);
                } else {
                    $f->ownedConsumer();
                    $f->create($mode, true);
                }
                $f->publisher->publish($f->subject, 'before');
                $f->waitRows(1);
                $field = $mode === 'mirror' ? 'mirror' : 'sources';
                $fault = new \Dorpmaster\Nats\Tests\Support\RecordingStreamSourceTransport(new \Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport($f->writer->client), static function (string $suffix, JsonObject $reply) use ($f, $field): JsonObject {
                    if ($suffix !== 'STREAM.INFO.' . $f->target) {
                        return $reply;
                    }
                    $old    = $reply->getObject('config')->getRawValue($field);
                    $needle = '"' . $field . '":' . $old;
                    self::assertSame(1, substr_count($reply->toJson(), $needle));
                    return new JsonObject(str_replace($needle, '"' . $field . '":null', $reply->toJson()));
                });
                try {
                    (new \Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin($fault))->updateStream($f->target, new StreamConfigPatch(maxConsumers:7));
                    self::fail('Corrupt snapshot must not disable a link');
                } catch (JetStreamApiException $error) {
                    self::assertStringContainsString($field, $error->getMessage());
                }
                self::assertCount(1, $fault->records);
                self::assertSame([], array_filter($fault->records, static fn(array $r): bool=>str_contains($r['subject'], '.STREAM.UPDATE.')));
                $f->publisher->publish($f->subject, 'after');
                $rows = $f->waitRows(2);
                $this->assertRows($rows, ['before','after']);
                $info = $f->reader->admin->getStreamInfo($f->target);
                self::assertNotNull($info->getConfiguration()->get($field));
                if (getenv('NATS_EXPECTED_VERSION') !== '2.12.6') {
                    self::assertSame('OWNED', $f->reader->admin->getConsumerInfo($f->origin, 'OWNED')->getName());
                }
                $f->trace('null-info-' . $mode, ['fault_requests' => $fault->records,'update_writes' => 0,'current_info' => $info->getResponse()->toJson(),'rows' => $rows]);
            } finally {
                $f->close();
            }
        });
    }
    public static function prefixRetentions(): iterable
    {
        foreach (['$JS.API','$JS.EU.API'] as $prefix) {
            foreach (['source','mirror'] as $mode) {
                foreach (['limits','workqueue','interest'] as $retention) {
                                yield [$prefix,$mode,$retention];
                }
            }
        }
    }
    #[DataProvider('prefixRetentions')]
    public function testManagedRetentionGateIsIndependentOfApiPrefix(string $prefix, string $mode, string $retention): void
    {
        $this->enabled();$this->runAsyncTest(function () use ($prefix, $mode, $retention): void {
            $f = new StreamSourceFixture($retention);
            try {
                if ($retention === 'interest') {
                    $f->admin->createOrUpdateConsumer($f->origin, new ConsumerConfig('HOLD'));
                }
                $f->publisher->publish($f->subject, 'before');
                $transport = new \Dorpmaster\Nats\Tests\Support\RecordingStreamSourceTransport(new \Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport($f->writer->client, apiPrefix:$prefix));
                $admin     = new \Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin($transport);
                $link      = new StreamSource($f->origin);
                $allowed   = getenv('NATS_EXPECTED_VERSION') !== '2.12.6' || $retention === 'limits';
                try {
                    $admin->createStream(new StreamConfig($f->target, [], mirror:$mode === 'mirror' ? $link : null, sources:$mode === 'source' ? [$link] : null));
                    self::assertTrue($allowed);
                } catch (JetStreamApiException $error) {
                    self::assertFalse($allowed);
                }
                $writes = array_filter($transport->records, static fn(array $r): bool=>str_contains($r['subject'], '.STREAM.CREATE.'));
                self::assertCount($allowed ? 1 : 0, $writes);
                if ($allowed) {
                    $f->publisher->publish($f->subject, 'after');
                    $rows = $f->waitRows(2);
                    $this->assertRows($rows, ['before','after']);
                } else {
                    try {
                        $f->reader->admin->getStreamInfo($f->target);
                        self::fail('No target may be created');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(404, $error->getApiCode());
                    }
                    $rows = [];
                }
                $f->trace('prefix-' . str_replace('$', '', str_replace('.', '-', $prefix)) . '-' . $mode . '-' . $retention, ['prefix' => $prefix,'retention' => $retention,'requests' => $transport->records,'create_writes' => count($writes),'rows' => $rows]);
            } finally {
                $f->close();
            }
        });
    }
    #[DataProvider('modes')]
    public function testReplicatedCopySurvivesLeaderChanges(string $mode): void
    {
        $this->enabled(true);$this->runAsyncTest(function () use ($mode): void {
            $f = new StreamSourceFixture(replicas:3);try {
                $f->ownedConsumer();
                $f->publisher->publish($f->subject, 'before');
                $f->create($mode, true);
                $f->publisher->publish($f->subject, 'after');
                $this->assertRows($f->waitRows(2), ['before','after']);
                $leaders = [];
                foreach ([$f->origin,$f->target] as $stream) {
                    $old = $f->reader->admin->getStreamInfo($stream)->getCluster()->leader;
                    $f->transport->requestJson('STREAM.LEADER.STEPDOWN.' . $stream, new JsonObject('{}'), 5000);
                    $deadline = hrtime(true) + 15_000_000_000;
                    do {
                        \Amp\delay(.05);
                        $current = $f->reader->admin->getStreamInfo($stream)->getCluster()->leader;
                    } while (($current === null || $current === $old) && hrtime(true) < $deadline);
                    self::assertNotNull($current);
                    self::assertNotSame($old, $current);
                    $leaders[$stream] = [$old,$current];
                }
                $this->assertRows($f->rows(), ['before','after']);
                $f->publisher->publish($f->subject, 'after-leader-change');
                $rows = $f->waitRows(3);
                $this->assertRows($rows, ['before','after','after-leader-change']);
                $f->trace('r3-' . $mode, ['leaders' => $leaders,'rows' => $rows,'info' => $f->reader->admin->getStreamInfo($f->target)->getResponse()->toJson()]);
            } finally {
                $f->close();
            }
        });
    }

    public static function multipleSourceCases(): iterable
    {
        foreach (['normal', 'truncated', 'substituted', 'reordered', 'explicit_remove'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('multipleSourceCases')]
    public function testMultipleObservedSourcesSurviveUnrelatedUpdate(string $case): void
    {
        $this->enabled();
        $this->runAsyncTest(function () use ($case): void {
            $f        = new StreamSourceFixture();
            $b        = $f->origin . '_B';
            $c        = $f->origin . '_C';
            $subjectB = $f->subject . '.b';
            $subjectC = $f->subject . '.c';
            try {
                foreach ([[$b, $subjectB], [$c, $subjectC]] as [$name, $subject]) {
                    $f->admin->createStream(new StreamConfig($name, [$subject], 'file'));
                }
                $f->admin->createStream(new StreamConfig($f->target, [], 'file', sources:[new StreamSource($f->origin), new StreamSource($b)]));
                $f->publisher->publish($f->subject, 'before-A');
                $f->publisher->publish($subjectB, 'before-B');
                $f->waitRows(2);
                $fault    = new \Dorpmaster\Nats\Tests\Support\RecordingStreamSourceTransport(new \Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport($f->writer->client), static function (string $suffix, JsonObject $reply) use ($f, $case, $b, $c): JsonObject {
                    if ($suffix !== 'STREAM.INFO.' . $f->target) {
                        return $reply;
                    }
                    $config  = $reply->getObject('config');
                    $sources = $config->get('sources');
                    if ($case === 'truncated') {
                        $sources = array_values(array_filter($sources, static fn(object $source): bool => $source->name !== $b));
                    } elseif ($case === 'substituted') {
                        foreach ($sources as $source) {
                            if ($source->name === $b) {
                                $source->name = $c;
                            }
                        }
                    } elseif ($case === 'reordered') {
                        $sources = array_reverse($sources);
                    }
                    $needle = '"sources":' . $config->getRawValue('sources');
                    self::assertSame(1, substr_count($reply->toJson(), $needle));
                    // Mutate only config.sources; observed sources remain the authentic server snapshot.
                    return new JsonObject(str_replace($needle, '"sources":' . json_encode($sources, JSON_THROW_ON_ERROR), $reply->toJson()));
                });
                $rejected = in_array($case, ['truncated', 'substituted'], true);
                $error    = null;
                try {
                    (new \Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin($fault))->updateStream($f->target, $case === 'explicit_remove' ? new StreamConfigPatch(sources:[new StreamSource($f->origin)]) : new StreamConfigPatch(maxConsumers:7));
                    self::assertFalse($rejected, 'An inconsistent snapshot must fail before UPDATE');
                } catch (JetStreamApiException $caught) {
                    self::assertTrue($rejected);
                    self::assertStringContainsString('config.sources', $caught->getMessage());
                    $error = $caught->getMessage();
                }
                $updates = array_values(array_filter($fault->records, static fn(array $record): bool => str_contains($record['subject'], '.STREAM.UPDATE.')));
                self::assertCount($rejected ? 0 : 1, $updates);
                $info  = $f->reader->admin->getStreamInfo($f->target);
                $names = array_column($info->getConfiguration()->get('sources'), 'name');
                sort($names);
                $expectedNames = $case === 'explicit_remove' ? [$f->origin] : [$f->origin, $b];
                sort($expectedNames);
                self::assertSame($expectedNames, $names);
                self::assertSame($case === 'explicit_remove' || $rejected ? -1 : 7, $info->getConfiguration()->get('max_consumers'));
                $f->publisher->publish($subjectC, 'after-C');
                $f->publisher->publish($subjectB, 'after-B');
                $f->publisher->publish($f->subject, 'after-A');
                $rows     = $f->waitRows($case === 'explicit_remove' ? 3 : 4);
                $payloads = array_map('hex2bin', array_column($rows, 'payload_hex'));
                sort($payloads);
                $expected = $case === 'explicit_remove' ? ['before-A', 'before-B', 'after-A'] : ['before-A', 'before-B', 'after-A', 'after-B'];
                sort($expected);
                self::assertSame($expected, $payloads);
                $f->trace('multi-' . $case, ['fault_requests' => $fault->records, 'update_writes' => count($updates), 'error' => $error, 'current_info' => $info->getResponse()->toJson(), 'rows' => $rows]);
            } finally {
                try {
                    // Remove the receiver before its origins; tolerate an incomplete setup.
                    foreach ([$f->target, $b, $c] as $name) {
                        try {
                            $f->admin->deleteStream($name);
                        } catch (JetStreamApiException $error) {
                            if ($error->getApiCode() !== 404) {
                                throw $error;
                            }
                        }
                    }
                } finally {
                    $f->close();
                }
            }
        });
    }
}
