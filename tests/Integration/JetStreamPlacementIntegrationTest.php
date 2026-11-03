<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamPlacementException;
use Dorpmaster\Nats\Domain\JetStream\Model\{JsonObject,StreamInfo};
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\{AsyncTestTools,JetStreamPlacementFixture};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamPlacementIntegrationTest extends TestCase
{
    use AsyncTestTools;

    private function enabled(string $mode): void
    {
        if (getenv('NATS_PLACEMENT_MODE') !== $mode) {
            self::markTestSkipped('Run the N8 placement profile');
        }
        $this->setTimeout(45);
    }
    public static function oldCommands(): iterable
    {
        foreach (['$JS.API','$JS.EU.API'] as $prefix) {
            foreach (['cancel','stream','consumer','server'] as $operation) {
                yield [$prefix,$operation];
            }
        }
    }
    #[DataProvider('oldCommands')]
    public function testOldApiDoesNotWriteOrChangeResources(string $prefix, string $operation): void
    {
        $this->enabled('old');$this->runAsyncTest(function () use ($prefix, $operation): void {
            $f      = new JetStreamPlacementFixture('A', $prefix);
            $reader = new JetStreamPlacementFixture('A', $prefix);
            try {
                $f->seed(1);
                $before = $reader->admin->getStreamInfo($f->stream);
                $from   = count($f->transport->records);
                try {
                    match ($operation) {
                        'cancel'=>$f->admin->cancelStreamMove($f->stream),'stream'=>$f->admin->evacuateStreamPeer($f->stream, 'n1'),'consumer'=>$f->admin->evacuateConsumerPeer($f->stream, 'C', 'n1'),'server'=>$f->admin->evacuateServer(peer:'n1')
                    };
                    self::fail('Old API must be rejected');
                } catch (JetStreamPlacementException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                }
                $requests = array_slice($f->transport->records, $from);
                self::assertSame([], $this->commands($requests));
                self::assertSame($before->getConfiguration()->toJson(), $reader->admin->getStreamInfo($f->stream)->getConfiguration()->toJson());
                self::assertSame(JetStreamPlacementFixture::payload(1), $this->message($reader, $f->stream, 1));
                self::assertSame('C', $reader->admin->getConsumerInfo($f->stream, 'C')->getName());
                $this->trace('old-' . str_replace('.', '-', $prefix) . '-' . $operation, ['requests' => $requests,'before' => $before->getResponse()->toJson(),'after' => $reader->admin->getStreamInfo($f->stream)->getResponse()->toJson(),'payload_hex' => bin2hex($this->message($reader, $f->stream, 1))]);
            } finally {
                $reader->close();
                $f->close();
            }
        });
    }
    public static function assets(): iterable
    {
        yield ['stream'];
        yield ['consumer'];
    }
    #[DataProvider('assets')]
    public function testEvacuationConvergesAndPreservesData(string $asset): void
    {
        $this->enabled('cluster');$this->runAsyncTest(function () use ($asset): void {
            $f      = new JetStreamPlacementFixture();
            $reader = new JetStreamPlacementFixture();
            try {
                $f->seed(3, $asset === 'consumer' ? ['num_replicas' => 3] : [], $asset === 'stream' ? 3 : 5);
                $before         = $this->readyStream($reader->admin, $f->stream, $asset === 'stream' ? 3 : 5);
                $consumerBefore = $this->readyConsumer($reader->admin, $f->stream, 'C', 3);
                $peer           = $asset === 'stream' ? $before->getCluster()->replicas[0]->name : $consumerBefore->getCluster()->replicas[0]->name;
                $from           = count($f->transport->records);
                $ack            = $asset === 'stream' ? $f->admin->evacuateStreamPeer($f->stream, $peer, 10000) : $f->admin->evacuateConsumerPeer($f->stream, 'C', $peer, 10000);
                self::assertTrue($ack->response->get('success'));
                $after = $asset === 'stream' ? $reader->admin->waitForStreamEvacuation($f->stream, $peer, 3, 20000) : $reader->admin->waitForConsumerEvacuation($f->stream, 'C', $peer, 3, 20000);
                self::assertNotContains($peer, $this->names($after->getCluster()));
                if ($asset === 'consumer') {
                    self::assertSame($this->names($before->getCluster()), $this->names($reader->admin->getStreamInfo($f->stream)->getCluster()));
                }
                // This fixture's consumer belongs to f.stream; construct its public pull with that identity.
                $messages = new \Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer($reader->transport, $f->stream, 'C');
                $batch    = $messages->fetch(3, 3000);
                self::assertCount(3, iterator_to_array($batch->messages()));
                foreach (iterator_to_array($batch->messages()) as $i => $message) {
                    self::assertSame(JetStreamPlacementFixture::payload($i + 1), $message->getPayload());
                    $batch->getAcker()->ack($message);
                }
                $f->client->request(new PubMessage($f->subject . '.a', JetStreamPlacementFixture::payload(4)), 3);
                $next = $messages->fetch(1, 3000);
                self::assertSame(JetStreamPlacementFixture::payload(4), iterator_to_array($next->messages())[0]->getPayload());
                $next->getAcker()->ack(iterator_to_array($next->messages())[0]);
                self::assertCount(1, $this->commands(array_slice($f->transport->records, $from)));
                $this->trace('evacuate-' . $asset, ['command_response' => $ack->response->toJson(),'setup_requests' => array_slice($f->transport->records, 0, $from),'requests' => array_slice($f->transport->records, $from),'before' => $before->getResponse()->toJson(),'consumer_before' => $consumerBefore->getResponse()->toJson(),'after' => $after->getResponse()->toJson(),'independent_payloads' => array_map(static fn($m)=>bin2hex($m->getPayload()), iterator_to_array($batch->messages())),'next_payload' => bin2hex(iterator_to_array($next->messages())[0]->getPayload())]);
            } finally {
                $reader->close();
                $f->close();
            }
        });
    }
    public function testCancelWithoutTransitionPreservesServerError(): void
    {
        $this->enabled('cluster');$this->runAsyncTest(function (): void {
            $f      = new JetStreamPlacementFixture();
            $reader = new JetStreamPlacementFixture();
            try {
                $f->seed(1);
                $before = $reader->admin->getStreamInfo($f->stream);
                $from   = count($f->transport->records);
                try {
                    $f->admin->cancelStreamMove($f->stream, 3000);
                    self::fail('No transition must fail');
                } catch (JetStreamPlacementException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertSame(400, $error->getApiCode());
                    self::assertGreaterThan(0, $error->getErrorCode());
                    $codes = [$error->getApiCode(),$error->getErrorCode(),$error->getDescription()];
                }
                self::assertCount(1, $this->commands(array_slice($f->transport->records, $from)));
                self::assertSame($before->getConfiguration()->toJson(), $reader->admin->getStreamInfo($f->stream)->getConfiguration()->toJson());
                self::assertSame(JetStreamPlacementFixture::payload(1), $this->message($reader, $f->stream, 1));
                $this->trace('cancel-no-transition', ['error' => $codes,'requests' => array_slice($f->transport->records, $from),'info' => $before->getResponse()->toJson()]);
            } finally {
                $reader->close();
                $f->close();
            }
        });
    }
    public static function identifiers(): iterable
    {
        yield ['name'];
        yield ['id'];
    }
    #[DataProvider('identifiers')]
    public function testExplicitSystemEvacuationAcrossTwoAccounts(string $identifier): void
    {
        $this->enabled('cluster');$this->runAsyncTest(function () use ($identifier): void {
            $a   = new JetStreamPlacementFixture('A');
            $b   = new JetStreamPlacementFixture('B');
            $ra  = new JetStreamPlacementFixture('A');
            $rb  = new JetStreamPlacementFixture('B');
            $sys = new JetStreamPlacementFixture('SYS');
            try {
                $a->seed(1, [], 5);
                $b->seed(1, [], 5);
                $beforeA = $this->readyStream($ra->admin, $a->stream, 5);
                $beforeB = $this->readyStream($rb->admin, $b->stream, 5);
                $shared  = array_values(array_diff(array_intersect($this->names($beforeA->getCluster()), $this->names($beforeB->getCluster())), [$beforeA->getCluster()->leader]));
                self::assertNotEmpty($shared);
                $peer   = $shared[0];
                $peerId = null;
                foreach ($beforeA->getCluster()->replicas as $p) {
                    if ($p->name === $peer) {
                        $peerId = $p->peerId;
                    }
                }
                self::assertNotNull($peerId);
                $from = count($a->transport->records);
                try {
                    $a->admin->evacuateServer(peer:$peer, timeoutMs:250);
                    self::fail('Ordinary account has no system diagnostics');
                } catch (JetStreamPlacementException $e) {
                    self::assertFalse($e->isOutcomeUnknown());
                }
                self::assertCount(0, $this->commands(array_slice($a->transport->records, $from)));
                $beforeConsumerA = $ra->admin->getConsumerInfo($a->stream, 'C');
                $beforeConsumerB = $rb->admin->getConsumerInfo($b->stream, 'C');
                $ack             = $identifier === 'name' ? $sys->admin->evacuateServer(peer:$peer, timeoutMs:5000) : $sys->admin->evacuateServer(peerId:$peerId, timeoutMs:5000);
                self::assertTrue($ack->response->get('success'));
                $afterA         = $ra->admin->waitForStreamEvacuation($a->stream, $peer, 5, 20000);
                $afterB         = $rb->admin->waitForStreamEvacuation($b->stream, $peer, 5, 20000);
                $afterConsumerA = $ra->admin->waitForConsumerEvacuation($a->stream, 'C', $peer, 5, 20000);
                $afterConsumerB = $rb->admin->waitForConsumerEvacuation($b->stream, 'C', $peer, 5, 20000);
                self::assertNotContains($peer, $this->names($afterConsumerA->getCluster()));
                self::assertNotContains($peer, $this->names($afterConsumerB->getCluster()));
                foreach ([[$a,$ra],[$b,$rb]] as [$writer,$reader]) {
                    self::assertSame(JetStreamPlacementFixture::payload(1), $this->message($reader, $writer->stream, 1));
                    $writer->client->request(new PubMessage($writer->subject . '.a', JetStreamPlacementFixture::payload(2)), 3);
                    self::assertSame(JetStreamPlacementFixture::payload(2), $this->message($reader, $writer->stream, 2));
                }
                self::assertCount(1, $this->commands($sys->transport->records));
                $this->trace('system-' . $identifier, ['peer' => $peer,'peer_id' => $peerId,'command_response' => $ack->response->toJson(),'requests' => $sys->transport->records,'before_a' => $beforeA->getResponse()->toJson(),'before_b' => $beforeB->getResponse()->toJson(),'after_a' => $afterA->getResponse()->toJson(),'after_b' => $afterB->getResponse()->toJson(),'consumer_before_a' => $beforeConsumerA->getResponse()->toJson(), 'consumer_before_b' => $beforeConsumerB->getResponse()->toJson(), 'consumer_after_a' => $afterConsumerA->getResponse()->toJson(), 'consumer_after_b' => $afterConsumerB->getResponse()->toJson(),'independent_payloads' => [bin2hex(JetStreamPlacementFixture::payload(1)),bin2hex(JetStreamPlacementFixture::payload(2))]]);
            } finally {
                $sys->close();
                $rb->close();
                $ra->close();
                $b->close();
                $a->close();
            }
        });
    }
    public function testCancelHeldScaleReturnsToOriginalConfigurationAndPeers(): void
    {
        $this->enabled('held');$this->runAsyncTest(function (): void {
            $wire   = [];
            $f      = new JetStreamPlacementFixture(onSend: static function (\Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface $message, int $session) use (&$wire): void {
                $frame = (string) $message;
                if (str_starts_with($frame, 'PUB ') || str_starts_with($frame, 'HPUB ')) {
                    $wire[] = ['session' => $session, 'frame' => $frame];
                }
            });
            $reader = new JetStreamPlacementFixture();
            try {
                $raw        = new JsonObject(file_get_contents('/placement-control/original.json'));
                $config     = $raw->getObject('config');
                $original   = new StreamInfo('N8_CANCEL', $config->get('subjects'), $config, $raw);
                $transition = $f->admin->getStreamInfo('N8_CANCEL');
                self::assertSame('3', $transition->getCluster()->desired->origin->replicas);
                self::assertNotNull($transition->getCluster()->desired->status);
                $domain       = new \Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport($f->client, apiPrefix: '$JS.EU');
                $beforeDomain = count($wire);
                try {
                    $domain->request('API.STREAM.CANCEL_MOVE.N8_CANCEL', [], 3000);
                    self::fail('Direct domain CANCEL_MOVE must stop before PUB');
                } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $error) {
                    self::assertSame(0, $error->getApiCode());
                    self::assertStringContainsString('connection-bound single attempt', $error->getDescription());
                    $domainFailure = $error->getDescription();
                }
                $domainPubs = count($wire) - $beforeDomain;
                self::assertSame(0, $domainPubs, 'No PUB of any kind from the rejected domain call');
                $stillMoving = $reader->admin->getStreamInfo('N8_CANCEL');
                self::assertNotNull($stillMoving->getCluster()->desired);
                self::assertSame('3', $stillMoving->getCluster()->desired->origin->replicas);
                self::assertSame($transition->getCreated()->getUnixNanoseconds(), $stillMoving->getCreated()->getUnixNanoseconds());
                self::assertSame('synthetic-n8-held', json_decode($this->message($reader, 'N8_CANCEL', 1), true)['marker']);
                $domainInfo = $domain->request('API.STREAM.INFO.N8_CANCEL', [], 3000);
                self::assertSame('io.nats.jetstream.api.v1.stream_info_response', $domainInfo['type']);
                self::assertSame('N8_CANCEL', $domainInfo['config']['name']);
                $domainGuard = ['error' => $domainFailure, 'pub_count' => $domainPubs, 'still_moving' => $stillMoving->getResponse()->toJson(), 'domain_info' => $domainInfo];
                $blocked     = count($f->transport->records);
                try {
                    $f->admin->evacuateStreamPeer('N8_CANCEL', $original->getCluster()->replicas[0]->name, 10000);
                    self::fail('The mapped evacuation must stop before its command');
                } catch (JetStreamPlacementException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertStringContainsString('subject mapping', $error->getDescription());
                }
                self::assertCount(0, $this->commands(array_slice($f->transport->records, $blocked)));
                $from     = count($f->transport->records);
                $response = $f->admin->cancelStreamMove('N8_CANCEL', 10000);
                self::assertSame('io.nats.jetstream.api.v1.stream_cancel_move_response', $response->getResponse()->get('type'));
                self::assertSame(3, $response->getConfiguration()->get('num_replicas'));
                file_put_contents('/placement-control/cancelled', 'accepted');
                $after = $reader->admin->waitForStreamRollback($transition, $original, 20000);
                self::assertSame($this->names($original->getCluster()), $this->names($after->getCluster()));
                self::assertNull($after->getCluster()->desired);
                self::assertSame('synthetic-n8-held', json_decode($this->message($reader, 'N8_CANCEL', 1), true)['marker']);
                $payload = "after-cancel\r\n\0";
                $f->client->request(new PubMessage('n8.held', $payload), 3);
                self::assertSame($payload, $this->message($reader, 'N8_CANCEL', 2));
                self::assertCount(1, $this->commands(array_slice($f->transport->records, $from)));
                $cancelFrames = array_values(array_filter($wire, static fn (array $record): bool => preg_match('/\APUB \$JS\.(?:[^.]+\.)?API\.STREAM\.CANCEL_MOVE\.N8_CANCEL /', $record['frame']) === 1));
                self::assertCount(1, $cancelFrames);
                self::assertStringStartsWith('PUB $JS.API.STREAM.CANCEL_MOVE.N8_CANCEL ', $cancelFrames[0]['frame']);
                $this->trace('cancel-held', ['domain_guard' => $domainGuard, 'cancel_frames' => $cancelFrames, 'original' => $original->getResponse()->toJson(),'before' => $transition->getResponse()->toJson(),'command_response' => $response->getResponse()->toJson(),'after' => $after->getResponse()->toJson(),'requests' => array_slice($f->transport->records, $from),'independent_next_payload' => bin2hex($payload)]);
            } finally {
                $reader->close();
                $f->close();
            }
        });
    }
    public static function mappedPrefixes(): iterable
    {
        yield ['$JS.API'];
        yield ['ALT.API'];
    }
    #[DataProvider('mappedPrefixes')]
    public function testEvacuationRejectsSplitCommandMappingBeforeEitherStreamChanges(string $prefix): void
    {
        $this->enabled('cluster');
        $this->runAsyncTest(function () use ($prefix): void {
            $f      = new JetStreamPlacementFixture('A', $prefix);
            $reader = new JetStreamPlacementFixture();
            try {
                foreach (['WANTED','OTHER'] as $stream) {
                    $reader->admin->createStream(new \Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig($stream, ['n8.route.' . $stream], 'file', 5));
                    $reader->client->request(new PubMessage('n8.route.' . $stream, 'before-' . $stream), 3);
                }
                $wanted = $this->readyStream($reader->admin, 'WANTED', 5);
                $other  = $this->readyStream($reader->admin, 'OTHER', 5);
                $shared = array_values(array_intersect($this->names($wanted->getCluster()), $this->names($other->getCluster())));
                self::assertNotEmpty($shared);
                $from = count($f->transport->records);
                try {
                    $f->admin->evacuateStreamPeer('WANTED', $shared[0], 10000);
                    self::fail('Split INFO/command routing must stop before evacuation');
                } catch (JetStreamPlacementException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertStringContainsString($prefix === '$JS.API' ? 'subject mapping' : 'route', $error->getDescription());
                    $failure = $error->getDescription();
                }
                self::assertCount(0, $this->commands(array_slice($f->transport->records, $from)));
                $after = [];
                foreach (['WANTED' => $wanted, 'OTHER' => $other] as $stream => $before) {
                    $info = $reader->admin->getStreamInfo($stream);
                    self::assertSame($this->names($before->getCluster()), $this->names($info->getCluster()));
                    self::assertSame('before-' . $stream, $this->message($reader, $stream, 1));
                    $reader->client->request(new PubMessage('n8.route.' . $stream, 'after-' . $stream), 3);
                    self::assertSame('after-' . $stream, $this->message($reader, $stream, 2));
                    $after[$stream] = $reader->admin->getStreamInfo($stream)->getResponse()->toJson();
                }
                $this->trace('routed-' . str_replace('.', '-', $prefix), ['changing_commands' => 0, 'failure' => $failure, 'before_wanted' => $wanted->getResponse()->toJson(), 'before_other' => $other->getResponse()->toJson(), 'after' => $after]);
            } finally {
                foreach (['WANTED','OTHER'] as $stream) {
                    try {
                        $reader->admin->deleteStream($stream);
                    } catch (\Throwable) {
                    }
                }
                $reader->close();
                $f->close();
            }
        });
    }
    public function testRollbackSnapshotIdentityAndExactPlacementOnRealServer(): void
    {
        $this->enabled('snapshot');
        $this->runAsyncTest(function (): void {
            $f = new JetStreamPlacementFixture();
            try {
                $case       = trim(file_get_contents('/placement-control/case'));
                $raw        = new JsonObject(file_get_contents('/placement-control/original.json'));
                $original   = new StreamInfo('N8_CANCEL', $raw->getObject('config')->get('subjects'), $raw->getObject('config'), $raw);
                $raw        = new JsonObject(file_get_contents('/placement-control/pending.json'));
                $transition = new StreamInfo('N8_CANCEL', $raw->getObject('config')->get('subjects'), $raw->getObject('config'), $raw);
                $before     = $f->admin->getStreamInfo('N8_CANCEL');
                if ($case === 'leader') {
                    $after = $f->admin->waitForStreamRollback($transition, $original, 5000);
                    self::assertSame($this->names($original->getCluster()), $this->names($after->getCluster()));
                    self::assertSame($original->getCreated()->getUnixNanoseconds(), $after->getCreated()->getUnixNanoseconds());
                    self::assertSame($original->getCluster()->raftGroup, $after->getCluster()->raftGroup);
                    self::assertNotSame($original->getCluster()->leader, $after->getCluster()->leader);
                } else {
                    try {
                        $f->admin->waitForStreamRollback($transition, $original, 250);
                        self::fail('A different instance/placement cannot confirm rollback');
                    } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $error) {
                        if ($case === 'placement') {
                            self::assertInstanceOf(\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException::class, $error);
                            self::assertSame(['1'], $before->getConfiguration()->getObject('placement')->get('tags'));
                            self::assertSame($original->getCreated()->getUnixNanoseconds(), $before->getCreated()->getUnixNanoseconds());
                        } else {
                            self::assertStringContainsString('instance identity', $error->getDescription());
                            self::assertNotSame($original->getCreated()->getUnixNanoseconds(), $before->getCreated()->getUnixNanoseconds());
                            self::assertNotSame($original->getCluster()->raftGroup, $before->getCluster()->raftGroup);
                            self::assertSame('0', $before->getState()->messages);
                        }
                    }
                    $after = $f->admin->getStreamInfo('N8_CANCEL');
                    self::assertSame($before->getCreated()->getUnixNanoseconds(), $after->getCreated()->getUnixNanoseconds());
                }
                self::assertSame($this->names($original->getCluster()), $this->names($after->getCluster()));
                self::assertCount(0, $this->commands($f->transport->records));
                $this->trace('snapshot-' . $case, ['changing_commands' => 0, 'original' => $original->getResponse()->toJson(), 'observed' => $after->getResponse()->toJson(), 'requests' => $f->transport->records]);
            } finally {
                $f->close();
            }
        });
    }

    private function commands(array $records): array
    {
        return array_values(array_filter($records, static fn(array $r): bool=>str_contains($r['subject'], '.EVACUATE') || str_contains($r['subject'], '.CANCEL_MOVE.')));
    }
    private function names($cluster): array
    {
        $names = [$cluster->leader];
        foreach ($cluster->replicas ?? [] as $p) {
            $names[] = $p->name;
        }sort($names, SORT_STRING);
        return $names;
    }
    private function message(JetStreamPlacementFixture $reader, string $stream, int $seq): string
    {
        return base64_decode($reader->transport->requestJson('STREAM.MSG.GET.' . $stream, new JsonObject('{"seq":' . $seq . '}'))->getObject('message')->get('data'), true);
    }
    private function readyStream(JetStreamAdmin $admin, string $stream, int $replicas): StreamInfo
    {
        $end = hrtime(true) + 15000000000;
        do {
            $info    = $admin->getStreamInfo($stream);
            $cluster = $info->getCluster();
            if ($cluster !== null && $cluster->desired === null && count($this->names($cluster)) === $replicas && array_filter($cluster->replicas ?? [], static fn($p)=>$p->current !== true || $p->offline === true || $p->pending === true || ($p->lag !== null && $p->lag !== '0')) === []) {
                return $info;
            }\Amp\delay(.03);
        } while (hrtime(true) < $end);
        throw new \RuntimeException('Initial placement did not settle');
    }
    private function readyConsumer(JetStreamAdmin $admin, string $stream, string $consumer, int $replicas): \Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo
    {
        $end = hrtime(true) + 15000000000;
        do {
            $info    = $admin->getConsumerInfo($stream, $consumer);
            $cluster = $info->getCluster();
            if ($cluster !== null && $cluster->desired === null && count($this->names($cluster)) === $replicas && array_filter($cluster->replicas ?? [], static fn ($p) => $p->current !== true || $p->offline === true || $p->pending === true || ($p->lag !== null && $p->lag !== '0')) === []) {
                return $info;
            }
            \Amp\delay(.03);
        } while (hrtime(true) < $end);
        throw new \RuntimeException('Initial consumer placement did not settle');
    }

    private function trace(string $name, array $data): void
    {
        if ($path = getenv('NATS_PLACEMENT_TRACE_DIR')) {
            file_put_contents($path . '/' . $name . '.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }
}
