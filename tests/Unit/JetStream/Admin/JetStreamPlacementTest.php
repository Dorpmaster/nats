<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Admin;

use Amp\DeferredCancellation;
use Dorpmaster\Nats\Tests\Support\PlacementDiagnostics;
use Dorpmaster\Nats\Domain\JetStream\Internal\PlacementResponseException;
use Dorpmaster\Nats\Domain\Client\{ServerInformation,ConnectionBoundRequestException};
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Capability\{JetStreamCapabilities,JetStreamCapabilitiesProviderInterface,Feature};
use Dorpmaster\Nats\Domain\JetStream\Exception\{JetStreamApiException,JetStreamPlacementException,JetStreamTimeoutException};
use Dorpmaster\Nats\Domain\JetStream\Model\{JsonObject,StreamInfo};
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamConnectionBoundTransportInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamPlacementTest extends TestCase
{
    private \Closure|null $diagnosticReply = null;
    private const string STREAM            = '{"type":"io.nats.jetstream.api.v1.stream_info_response","created":"2026-11-02T09:00:00.000000001Z","config":{"name":"S","num_replicas":3,"retention":"limits","metadata":{"_nats.level":"5"}},"cluster":{"name":"C","leader":"n1","replicas":[{"name":"n2","current":true,"lag":0},{"name":"n3","current":true,"lag":0}]}}';
    private const string CONSUMER          = '{"type":"io.nats.jetstream.api.v1.consumer_info_response","name":"C","stream_name":"S","config":{"durable_name":"C","num_replicas":3,"metadata":{"_nats.level":"5"}}}';
    private function transport(\Closure $handler, int|null $level = 5, string $prefix = '$JS.API'): object
    {
        $t = $this->createMockForIntersectionOfInterfaces([JetStreamConnectionBoundTransportInterface::class,JetStreamCapabilitiesProviderInterface::class]);
        $t->method('getCapabilities')->willReturn(new JetStreamCapabilities(new ServerInformation('id', '2.15.2', true, $level, 7, static fn()=>true), $prefix));
        $client = $this->createStubForIntersectionOfInterfaces([\Dorpmaster\Nats\Domain\Client\ClientInterface::class, \Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface::class]);
        $client->method('requestOnceOnConnection')->willReturnCallback(function ($message, int $connection) {
            if ($this->diagnosticReply !== null) {
                return ($this->diagnosticReply)($message, $connection);
            }
            $reply = PlacementDiagnostics::response($message->getSubject(), account: json_decode($message->getPayload(), true)['account'] ?? 'A');
            self::assertNotNull($reply);
            return new \Dorpmaster\Nats\Protocol\MsgMessage('reply', '1', $reply);
        });
        $t->method('getClient')->willReturn($client);
        $t->method('getSubscriptionIdHelper')->willReturn(new \Dorpmaster\Nats\Client\SubscriptionIdHelper());
        $t->expects(self::never())->method('request');
        $t->expects(self::never())->method('requestJsonOnce');
        $t->expects(self::never())->method('publishRequest');
        $t->method('requestJsonOnConnection')->willReturnCallback(static function (string $suffix, JsonObject $body, int $connection, int $timeout) use ($handler): JsonObject {
            self::assertSame(7, $connection);
            self::assertGreaterThan(0, $timeout);
            return $handler($suffix, $body);
        });
        return $t;
    }
    private static function invoke(JetStreamAdmin $a, string $operation, int $timeout = 1000, \Amp\Cancellation|null $cancel = null): mixed
    {
        return match ($operation) {
            'cancel'=>$a->cancelStreamMove('S', $timeout, $cancel),
            'stream'=>$a->evacuateStreamPeer('S', 'n2', $timeout, $cancel),
            'consumer'=>$a->evacuateConsumerPeer('S', 'C', 'n2', $timeout, $cancel),
            'server'=>$a->evacuateServer(peer:'n2', timeoutMs:$timeout, cancellation:$cancel),
        };
    }
    public static function commands(): iterable
    {
        yield ['cancel','STREAM.CANCEL_MOVE.S','stream_cancel_move_response'];
        yield ['stream','STREAM.PEER.EVACUATE.S','stream_evacuate_peer_response'];
        yield ['consumer','CONSUMER.PEER.EVACUATE.S.C','consumer_evacuate_peer_response'];
    }
    #[DataProvider('commands')]
    public function testExactSingleCommandAndResponse(string $operation, string $suffix, string $type): void
    {
        $writes = [];
        $t      = $this->transport(static function (string $subject, JsonObject $body) use (&$writes, $suffix, $type, $operation): JsonObject {
            if ($subject === 'INFO') {
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
            }
            if (str_starts_with($subject, 'STREAM.INFO')) {
                return new JsonObject(self::STREAM);
            }
            if (str_starts_with($subject, 'CONSUMER.INFO')) {
                return new JsonObject(self::CONSUMER);
            }
            self::assertSame($suffix, $subject);
            $writes[] = $body->toJson();
            return new JsonObject($operation === 'cancel' ? str_replace('stream_info_response', $type, self::STREAM) : '{"type":"io.nats.jetstream.api.v1.' . $type . '","success":true}');
        });
        $result = self::invoke(new JetStreamAdmin($t), $operation);
        self::assertSame([$operation === 'cancel' ? '{}' : '{"peer":"n2"}'], $writes);
        self::assertSame($operation === 'cancel' ? 'S' : 'n2', $operation === 'cancel' ? $result->getName() : $result->targets['peer']);
    }
    public static function oldCommands(): iterable
    {
        foreach ([3,4] as $level) {
            foreach (['$JS.API','$JS.EU.API'] as $prefix) {
                foreach (['cancel','stream','consumer','server'] as $command) {
                    yield [$level,$prefix,$command];
                }
            }
        }
    }
    #[DataProvider('oldCommands')]
    public function testOldTargetsNeverReceiveChangingCommands(int $level, string $prefix, string $operation): void
    {
        $t = $this->transport(static function (string $subject) use ($level): JsonObject {
            self::assertSame('INFO', $subject);
            return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":' . $level . '}}');
        }, $level, $prefix);
        try {
            self::invoke(new JetStreamAdmin($t), $operation);
            self::fail('Unsupported operation');
        } catch (JetStreamPlacementException $e) {
            self::assertFalse($e->isOutcomeUnknown());
        }
    }
    public static function corruptTargets(): iterable
    {
        yield ['{"type":"bad","api":{"level":5}}',self::STREAM];
        yield ['{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":"5"}}',self::STREAM];
        yield ['{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":4}}',self::STREAM];
        yield ['{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}',str_replace('"_nats.level":"5"', '"_nats.level":"4"', self::STREAM)];
        yield ['{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}',str_replace('"name":"S"', '"name":"OTHER"', self::STREAM)];
    }
    #[DataProvider('corruptTargets')]
    public function testUnknownTargetFailsBeforeCommand(string $account, string $stream): void
    {
        $t = $this->transport(static function (string $suffix) use ($account, $stream): JsonObject {
            self::assertContains($suffix, ['INFO','STREAM.INFO.S']);
            return new JsonObject($suffix === 'INFO' ? $account : $stream);
        }, null);
        try {
            (new JetStreamAdmin($t))->evacuateStreamPeer('S', 'n2');
            self::fail();
        } catch (JetStreamPlacementException $e) {
            self::assertFalse($e->isOutcomeUnknown());
        }
    }
    public static function failures(): iterable
    {
        yield [new ConnectionBoundRequestException(false, new \RuntimeException('unsent')),false,0,null];
        yield [new ConnectionBoundRequestException(true, new \RuntimeException('lost')),true,0,null];
        yield [new JetStreamApiException(400, 'untyped denied', errorCode:10071),true,400,10071];
        yield [new PlacementResponseException(new JsonObject('{"type":"io.nats.jetstream.api.v1.stream_evacuate_peer_response","error":{"code":400,"err_code":10071}}'), new JetStreamApiException(400, 'denied', errorCode:10071)),false,400,10071];
        yield [new JetStreamApiException(408, 'timeout'),true,408,null];
        yield [new JetStreamApiException(504, 'gateway timeout'),true,504,null];
    }
    #[DataProvider('failures')]
    public function testErrorRetainsCauseAndUncertainty(\Throwable $cause, bool $unknown, int $code, int|null $err): void
    {
        $t = $this->transport(static function (string $suffix) use ($cause): JsonObject {
            if ($suffix === 'INFO') {
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
            }
            if ($suffix === 'STREAM.INFO.S') {
                return new JsonObject(self::STREAM);
            }
            throw $cause;
        });
        try {
            (new JetStreamAdmin($t))->evacuateStreamPeer('S', 'n2');
            self::fail();
        } catch (JetStreamPlacementException $e) {
            self::assertSame($cause, $e->getPrevious());
            self::assertSame($unknown, $e->isOutcomeUnknown());
            self::assertSame($code, $e->getApiCode());
            self::assertSame($err, $e->getErrorCode());
            self::assertSame(['stream' => 'S','peer' => 'n2'], $e->getTargets());
            self::assertSame('stream_peer_evacuate', $e->getOperation());
        }
    }
    public static function badAcks(): iterable
    {
        yield ['{"type":"bad","success":true}'];
        yield ['{"type":"io.nats.jetstream.api.v1.stream_evacuate_peer_response","success":1}'];
        yield ['{"type":"io.nats.jetstream.api.v1.stream_evacuate_peer_response","success":false}'];
        yield ['{"type":"io.nats.jetstream.api.v1.stream_evacuate_peer_response"}'];
    }
    #[DataProvider('badAcks')]
    public function testMalformedResponseCannotConfirmSuccess(string $ack): void
    {
        $t = $this->transport(static fn(string $suffix): JsonObject=>new JsonObject(match ($suffix) {
'INFO'=>'{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}','STREAM.INFO.S'=>self::STREAM,default=>$ack
        }));
        try {
            (new JetStreamAdmin($t))->evacuateStreamPeer('S', 'n2');
            self::fail();
        } catch (JetStreamPlacementException $e) {
            self::assertTrue($e->isOutcomeUnknown());
        }
    }
    public function testInvalidArgumentsHaveNoRequests(): void
    {
        $t = $this->transport(static function (): never {
            self::fail('Invalid input must not request');
        });
        $a = new JetStreamAdmin($t);
        foreach ([fn()=>$a->cancelStreamMove("S\n"),fn()=>$a->evacuateStreamPeer('S', ''),fn()=>$a->evacuateConsumerPeer('S', "C\r", 'n1'),fn()=>$a->evacuateServer(),fn()=>$a->evacuateServer('n1', 'id'),fn()=>$a->evacuateServer(peerId:"id\0"),fn()=>$a->cancelStreamMove('S', 0),fn()=>$a->cancelStreamMove('S', PHP_INT_MAX)] as $call) {
            try {
                $call();
                self::fail('Expected invalid argument');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $c = new DeferredCancellation();
        $c->cancel();
        try {
            $a->cancelStreamMove('S', cancellation:$c->getCancellation());
            self::fail();
        } catch (JetStreamPlacementException $e) {
            self::assertFalse($e->isOutcomeUnknown());
        }
        self::assertSame(5, Feature::PLACEMENT_MANAGEMENT->minimumApiLevel());
    }
    public function testWaitDoesNotConfuseNoDesiredWithCompletion(): void
    {
        $replies = [self::STREAM,str_replace('"n2"', '"n4"', self::STREAM)];
        $reads   = [];
        $t       = $this->transport(static function (string $suffix) use (&$replies, &$reads): JsonObject {
            $reads[] = $suffix;
            return new JsonObject(array_shift($replies));
        });
        $info    = (new JetStreamAdmin($t))->waitForStreamEvacuation('S', 'n2', 3, 500);
        self::assertSame(['STREAM.INFO.S','STREAM.INFO.S'], $reads);
        self::assertSame('n4', $info->getCluster()->replicas[0]->name);
    }
    public function testWaitRejectsWrongPeerCountCurrentAndPending(): void
    {
        $cases = [str_replace(',{"name":"n3","current":true,"lag":0}', '', self::STREAM),str_replace('"current":true', '"current":false', self::STREAM),str_replace('"lag":0', '"lag":1', self::STREAM),str_replace('"lag":0', '"lag":0,"pending":true', self::STREAM)];
        foreach ($cases as $reply) {
            $t = $this->transport(static fn()=>new JsonObject($reply));
            try {
                (new JetStreamAdmin($t))->waitForStreamEvacuation('S', 'n9', 3, 8);
                self::fail();
            } catch (JetStreamTimeoutException) {
                self::assertTrue(true);
            }
        }
    }
    public function testRollbackUsesOriginConfigAndOriginalPeers(): void
    {
        $raw        = new JsonObject(self::STREAM);
        $original   = new StreamInfo('S', [], $raw->getObject('config'), $raw);
        $change     = $raw->withFields(['cluster' => ['name' => 'C','leader' => 'n1','desired' => ['origin' => ['replicas' => 3,'retention' => 'limits']]],'config' => ['name' => 'S','num_replicas' => 5,'retention' => 'interest']]);
        $transition = new StreamInfo('S', [], $change->getObject('config'), $change);
        $wrong      = str_replace('"n2"', '"n4"', self::STREAM);
        $wrong      = str_replace('"retention":"limits"', '"retention":"interest"', $wrong);
        $replies    = [$wrong,self::STREAM];
        $reads      = [];
        $t          = $this->transport(static function (string $suffix) use (&$replies, &$reads): JsonObject {
            $reads[] = $suffix;
            return new JsonObject(array_shift($replies));
        });
        self::assertSame('limits', (new JetStreamAdmin($t))->waitForStreamRollback($transition, $original, 500)->getConfiguration()->get('retention'));
        self::assertCount(2, $reads);
    }
    public function testOriginalMonotonicDeadlineIsCheckedBeforeQueuedWrite(): void
    {
        $deadline = new \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementDeadline(5, null);
        $lifetime = new \Dorpmaster\Nats\Client\WriteBuffer\RequestLifetime(null, $deadline->cancellation);
        usleep(10000); // No event-loop timer can fire during this synchronous producer work.
        self::assertTrue($deadline->cancellation->isRequested());
        self::assertFalse($lifetime->beginAttempt());
        self::assertFalse($lifetime->wasAttempted());
    }
    public function testAdmissionConsumesTheSameBudgetAndCannotWriteAfterExpiry(): void
    {
        $writes = 0;
        $t      = $this->transport(static function (string $suffix) use (&$writes): JsonObject {
            if ($suffix === 'INFO') {
                usleep(15000);
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
            }
            $writes++;
            return new JsonObject(self::STREAM);
        });
        try {
            (new JetStreamAdmin($t))->cancelStreamMove('S', 5);
            self::fail();
        } catch (JetStreamPlacementException $error) {
            self::assertFalse($error->isOutcomeUnknown());
        }
        self::assertSame(0, $writes);
    }
    public static function systemDiagnostics(): iterable
    {
        yield ['valid', true];
        yield ['level4', false];
        yield ['quoted_level', false];
        yield ['foreign_domain', false];
        yield ['foreign_cluster', false];
        yield ['foreign_server_id', false];
        yield ['missing_leader', false];
        yield ['wrong_local_id', false];
    }
    #[DataProvider('systemDiagnostics')]
    public function testSystemTargetMustBeTheEstablishedMetaLeader(string $case, bool $accepted): void
    {
        $writes                = [];
        $t                     = $this->transport(static function (string $suffix, JsonObject $body) use (&$writes): JsonObject {
            self::assertSame('SERVER.EVACUATE', $suffix);
            $writes[] = $body->toJson();
            return new JsonObject('{"type":"io.nats.jetstream.api.v1.meta_server_evacuate_response","success":true}');
        });
        $this->diagnosticReply = static function ($message, int $connection) use ($case) {
            self::assertSame(7, $connection);
            if (!str_ends_with($message->getSubject(), '.JSZ') && ($reply = PlacementDiagnostics::response($message->getSubject(), account: json_decode($message->getPayload(), true)['account'] ?? 'SYS')) !== null) {
                return new \Dorpmaster\Nats\Protocol\MsgMessage('reply', '1', $reply);
            }
            if ($message->getSubject() === '$SYS.REQ.SERVER.id.JSZ') {
                $reply = ['server' => ['id' => $case === 'wrong_local_id' ? 'wrong' : 'id','name' => 'connected','domain' => 'EU'],'data' => ['server_id' => 'id','meta_cluster' => ['name' => 'CLUSTER','leader' => 'leader']]];
            } else {
                self::assertSame('$SYS.REQ.SERVER.PING.JSZ', $message->getSubject());
                self::assertSame(['leader_only' => true,'server_name' => 'leader','exact_match' => true,'domain' => 'EU'], json_decode($message->getPayload(), true));
                $reply = ['server' => ['id' => 'LEADER_ID','name' => 'leader','domain' => 'EU'],'data' => ['server_id' => 'LEADER_ID','meta_cluster' => ['name' => 'CLUSTER','leader' => 'leader'],'api' => ['level' => 5]]];
                if ($case === 'level4') {
                    $reply['data']['api']['level'] = 4;
                }
                if ($case === 'quoted_level') {
                    $reply['data']['api']['level'] = '5';
                }
                if ($case === 'foreign_domain') {
                    $reply['server']['domain'] = 'OTHER';
                }
                if ($case === 'foreign_cluster') {
                    $reply['data']['meta_cluster']['name'] = 'OTHER';
                }
                if ($case === 'foreign_server_id') {
                    $reply['server']['id'] = 'OTHER';
                }
                if ($case === 'missing_leader') {
                    unset($reply['data']['meta_cluster']['leader']);
                }
            }
            return new \Dorpmaster\Nats\Protocol\MsgMessage('reply', '1', json_encode($reply, JSON_THROW_ON_ERROR));
        };
        try {
            $ack = (new JetStreamAdmin($t))->evacuateServer(peerId:'peer123');
            self::assertTrue($accepted);
            self::assertSame(['peer_id' => 'peer123'], $ack->targets);
        } catch (JetStreamPlacementException $error) {
            self::assertFalse($accepted);
            self::assertFalse($error->isOutcomeUnknown());
        }
        self::assertSame($accepted ? ['{"peer_id":"peer123"}'] : [], $writes);
    }
    public function testLegacyTransportIsNotRequiredToImplementNewCapability(): void
    {
        $t = $this->createMock(\Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface::class);
        $t->expects(self::never())->method('request');
        try {
            (new JetStreamAdmin($t))->cancelStreamMove('S');
            self::fail();
        } catch (JetStreamPlacementException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertStringContainsString('JetStreamConnectionBoundTransportInterface', $error->getDescription());
        }
    }
    public static function badCancelReplies(): iterable
    {
        yield [str_replace(['stream_info_response','"name":"S"'], ['stream_cancel_move_response','"name":"OTHER"'], self::STREAM)];
        yield [str_replace(['stream_info_response','"name":"S"'], ['stream_cancel_move_response','"name":9223372036854775808'], self::STREAM)];
    }
    #[DataProvider('badCancelReplies')]
    public function testCancelMustConfirmRequestedStream(string $ack): void
    {
        $t = $this->transport(static fn(string $suffix): JsonObject=>new JsonObject(match ($suffix) {
'INFO'=>'{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}','STREAM.INFO.S'=>self::STREAM,default=>$ack
        }));
        try {
            (new JetStreamAdmin($t))->cancelStreamMove('S');
            self::fail();
        } catch (JetStreamPlacementException $error) {
            self::assertTrue($error->isOutcomeUnknown());
        }
    }
    public function testWeakAndStrictCallersCannotCoerceMutationArguments(): void
    {
        $t     = $this->transport(static function (): never {
            self::fail('Invalid original type must not create any request');
        });
        $admin = new JetStreamAdmin($t);
        foreach (['stream', 'consumer', 'peer', 'server', 'peerId', 'timeoutMs'] as $field) {
            $values = $field === 'timeoutMs' ? [true, false, 1.5, '2000', []] : [true, false, 1.5, 1, []];
            foreach ($values as $value) {
                foreach ([false, true] as $weak) {
                    try {
                        if ($weak) {
                            \Dorpmaster\Nats\Tests\Support\WeakPlacementCaller::call($admin, $field, $value);
                        } else {
                            match ($field) {
                                'stream' => $admin->cancelStreamMove($value),
                                'consumer' => $admin->evacuateConsumerPeer('S', $value, 'n2'),
                                'peer' => $admin->evacuateStreamPeer('S', $value),
                                'server' => $admin->evacuateServer(peer: $value),
                                'peerId' => $admin->evacuateServer(peerId: $value),
                                'timeoutMs' => $admin->cancelStreamMove('S', timeoutMs: $value),
                            };
                        }
                        self::fail('Expected InvalidArgumentException for ' . $field . ' from ' . ($weak ? 'weak' : 'strict') . ' caller');
                    } catch (\InvalidArgumentException $error) {
                        self::assertStringContainsString($field === 'server' ? 'peer' : $field, $error->getMessage());
                    }
                }
            }
        }
    }
    public function testLostAdmissionConnectionNeverFallsBackToReplayableInfo(): void
    {
        $current = true;
        $t       = $this->createMockForIntersectionOfInterfaces([JetStreamConnectionBoundTransportInterface::class,JetStreamCapabilitiesProviderInterface::class]);
        $t->method('getCapabilities')->willReturn(new JetStreamCapabilities(new ServerInformation('id', '2.15.2', true, 5, 7, static function () use (&$current): bool {
            return $current;
        })));
        $t->expects(self::never())->method('requestJson');
        $t->expects(self::never())->method('requestJsonOnce');
        $t->expects(self::once())->method('requestJsonOnConnection')->with('INFO')->willReturnCallback(static function () use (&$current): JsonObject {
            $current = false;
            return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
        });
        try {
            (new JetStreamAdmin($t))->evacuateStreamPeer('S', 'n2');
            self::fail();
        } catch (JetStreamPlacementException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertStringContainsString('no longer current', $error->getDescription());
        }
    }
    public function testObserverDoesNotQueueReplayableInfoOnAStaleConnection(): void
    {
        $t = $this->createMockForIntersectionOfInterfaces([JetStreamConnectionBoundTransportInterface::class,JetStreamCapabilitiesProviderInterface::class]);
        $t->method('getCapabilities')->willReturn(new JetStreamCapabilities(new ServerInformation('id', '2.15.2', true, 5, 7, static fn(): bool=>false)));
        $t->expects(self::never())->method('requestJson');
        $t->expects(self::never())->method('requestJsonOnConnection');
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage('no longer current');
        (new JetStreamAdmin($t))->waitForStreamEvacuation('S', 'n2', 3);
    }
    /** @return array{StreamInfo,StreamInfo} */
    private function rollbackPair(): array
    {
        $raw     = (new JsonObject(self::STREAM))->withFields(['domain' => 'EU', 'config' => ['name' => 'S', 'num_replicas' => 3, 'retention' => 'limits', 'placement' => ['cluster' => 'C', 'tags' => ['01']]]]);
        $cluster = $raw->getObject('cluster')->withFields(['raft_group' => 'R3-original']);
        $raw     = $raw->withFields(['cluster' => $cluster->toArray()]);
        $change  = $raw->withFields(['cluster' => ['raft_group' => 'R3-original', 'leader' => 'n1', 'desired' => ['origin' => ['replicas' => 3, 'retention' => 'limits', 'placement' => ['tags' => ['01'], 'cluster' => 'C']]]]]);
        return [new StreamInfo('S', [], $raw->getObject('config'), $raw), new StreamInfo('S', [], $change->getObject('config'), $change)];
    }
    public static function instanceChanges(): iterable
    {
        foreach (['created', 'raft_group', 'domain', 'missing_created'] as $field) {
            foreach ([false, true] as $baseline) {
                yield [$field, $baseline];
            }
        }
    }
    #[DataProvider('instanceChanges')]
    public function testRollbackRejectsAnotherStreamInstance(string $field, bool $baseline): void
    {
        [$original, $transition] = $this->rollbackPair();
        $raw                     = ($baseline ? $transition : $original)->getResponse();
        $raw                     = match ($field) {
            'created' => $raw->withFields(['created' => '2026-11-02T09:00:00.000000002Z']),
            'missing_created' => $raw->withFields(['created' => null]),
            'domain' => $raw->withFields(['domain' => 'OTHER']),
            'raft_group' => $raw->withFields(['cluster' => $raw->getObject('cluster')->withFields(['raft_group' => 'R3-recreated'])->toArray()]),
        };
        $reads = 0;
        $t     = $this->transport(static function () use ($raw, &$reads): JsonObject {
            $reads++;
            return $raw;
        });
        if ($baseline) {
            $transition = new StreamInfo('S', [], $raw->getObject('config'), $raw);
        }
        try {
            (new JetStreamAdmin($t))->waitForStreamRollback($transition, $original, 500);
            self::fail('A different stream instance cannot confirm rollback');
        } catch (JetStreamApiException $error) {
            self::assertStringContainsString('instance identity', $error->getDescription());
        }
        self::assertSame($baseline ? 0 : 1, $reads);
    }
    public function testRollbackPlacementStringsAreExactAndObjectKeyOrderIsIrrelevant(): void
    {
        [$original, $transition] = $this->rollbackPair();
        $raw                     = $original->getResponse();
        $changed                 = $raw->withFields(['config' => $raw->getObject('config')->withFields(['placement' => ['tags' => ['1'], 'cluster' => 'C']])->toArray()]);
        // Keep the same peers but elect a different leader on the final poll.
        $settled = $raw->withFields(['cluster' => ['raft_group' => 'R3-original', 'leader' => 'n2', 'replicas' => [['name' => 'n1', 'current' => true, 'lag' => 0], ['name' => 'n3', 'current' => true, 'lag' => 0]]]]);
        $replies = [$changed, $settled];
        $t       = $this->transport(static function () use (&$replies): JsonObject {
            return array_shift($replies);
        });
        $result  = (new JetStreamAdmin($t))->waitForStreamRollback($transition, $original, 500);
        self::assertSame([], $replies, 'The numeric-looking different tag must not finish observation');
        self::assertSame(['01'], $result->getConfiguration()->getObject('placement')->get('tags'));
        self::assertSame('n2', $result->getCluster()->leader);
    }
    public function testRollbackRejectsDifferentOriginTagBeforePolling(): void
    {
        [$original, $transition] = $this->rollbackPair();
        $raw                     = new JsonObject(str_replace('"01"', '"1"', $transition->getResponse()->toJson()));
        $t                       = $this->transport(static function (): never {
            self::fail('Invalid baseline must not poll');
        });
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('origin configuration mismatch');
        (new JetStreamAdmin($t))->waitForStreamRollback(new StreamInfo('S', [], $raw->getObject('config'), $raw), $original);
    }
    public function testConsumerObserverCanPollTwiceOnTheOriginalConnection(): void
    {
        $raw     = (new JsonObject(self::CONSUMER))->withFields(['config' => ['durable_name' => 'C', 'num_replicas' => 1]]);
        $replies = [$raw->withFields(['cluster' => ['leader' => 'n2']]), $raw->withFields(['cluster' => ['leader' => 'n3']])];
        $reads   = [];
        $t       = $this->transport(static function (string $suffix) use (&$replies, &$reads): JsonObject {
            $reads[] = $suffix;
            return array_shift($replies);
        });
        $result  = (new JetStreamAdmin($t))->waitForConsumerEvacuation('S', 'C', 'n2', 1, 500);
        self::assertSame(['CONSUMER.INFO.S.C', 'CONSUMER.INFO.S.C'], $reads);
        self::assertSame('n3', $result->getCluster()->leader);
    }
    public static function observers(): iterable
    {
        yield ['stream'];
        yield ['consumer'];
        yield ['rollback'];
    }
    #[DataProvider('observers')]
    public function testWholeObserverKeepsOriginalConnection(string $observer): void
    {
        [$original, $transition] = $this->rollbackPair();
        $current                 = true;
        $t                       = $this->createMockForIntersectionOfInterfaces([JetStreamConnectionBoundTransportInterface::class, JetStreamCapabilitiesProviderInterface::class]);
        $t->expects(self::once())->method('getCapabilities')->willReturn(new JetStreamCapabilities(new ServerInformation('id', '2.15.2', true, 5, 7, static function () use (&$current): bool {
            return $current;
        })));
        $t->expects(self::once())->method('requestJsonOnConnection')->willReturnCallback(static function () use (&$current, $observer, $original): JsonObject {
            $current = false;
            if ($observer === 'consumer') {
                return (new JsonObject(self::CONSUMER))->withFields(['cluster' => ['leader' => 'n2']]);
            }
            return $observer === 'rollback' ? $original->getResponse()->withFields(['cluster' => ['raft_group' => 'R3-original', 'leader' => 'n2']]) : new JsonObject(self::STREAM);
        });
        $admin = new JetStreamAdmin($t);
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage('no longer current');
        match ($observer) {
            'stream' => $admin->waitForStreamEvacuation('S', 'n2', 3, 500),
            'consumer' => $admin->waitForConsumerEvacuation('S', 'C', 'n2', 1, 500),
            'rollback' => $admin->waitForStreamRollback($transition, $original, 500),
        };
    }
    public static function allCommands(): iterable
    {
        yield ['cancel', '$JS.API.STREAM.CANCEL_MOVE.S'];
        yield ['stream', '$JS.API.STREAM.PEER.EVACUATE.S'];
        yield ['consumer', '$JS.API.CONSUMER.PEER.EVACUATE.S.C'];
        yield ['server', '$JS.API.SERVER.EVACUATE'];
    }
    #[DataProvider('allCommands')]
    public function testChangingCommandMappingFailsBeforeMutation(string $operation, string $subject): void
    {
        $t                     = $this->transport(static function (string $suffix): JsonObject {
            self::assertSame('INFO', $suffix, 'No resource or changing command after rejected route');
            return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
        });
        $this->diagnosticReply = static function ($message) use ($subject, $operation) {
            $raw = PlacementDiagnostics::response($message->getSubject(), mappings: [$subject => 'OTHER'], account: json_decode($message->getPayload(), true)['account'] ?? ($operation === 'server' ? 'SYS' : 'A'));
            self::assertNotNull($raw);
            return new \Dorpmaster\Nats\Protocol\MsgMessage('reply', '1', $raw);
        };
        try {
            self::invoke(new JetStreamAdmin($t), $operation);
            self::fail('A mapping of the changing command cannot be certified');
        } catch (JetStreamPlacementException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertStringContainsString('subject mapping', $error->getDescription());
        }
    }
    public static function errorEnvelopes(): iterable
    {
        foreach (['consumer_info_response', null, 'stream_cancel_move_response'] as $type) {
            foreach ([[400,10129],[408,null],[504,null]] as [$code,$err]) {
                yield [$type,$code,$err,$type !== 'stream_cancel_move_response' || $code !== 400];
            }
        }
    }
    #[DataProvider('errorEnvelopes')]
    public function testRejectionMustHaveExpectedEnvelope(string|null $type, int $code, int|null $err, bool $unknown): void
    {
        $raw = JsonObject::fromArray(['error' => ['code' => $code, 'err_code' => $err, 'description' => 'synthetic rejection']]);
        if ($type !== null) {
            $raw = $raw->withFields(['type' => 'io.nats.jetstream.api.v1.' . $type]);
        }
        $cause = new PlacementResponseException($raw, new JetStreamApiException($code, 'synthetic rejection', errorCode: $err));
        $t     = $this->transport(static function (string $suffix) use ($cause): JsonObject {
            return match ($suffix) {
                'INFO' => new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}'),
                'STREAM.INFO.S' => new JsonObject(self::STREAM),
                default => throw $cause,
            };
        });
        try {
            (new JetStreamAdmin($t))->cancelStreamMove('S');
            self::fail('Expected rejection');
        } catch (JetStreamPlacementException $error) {
            self::assertSame($unknown, $error->isOutcomeUnknown());
            self::assertSame($code, $error->getApiCode());
            self::assertSame($err, $error->getErrorCode());
            self::assertSame($cause, $error->getPrevious());
        }
    }
    public static function nativeRoutes(): iterable
    {
        foreach (['unchanged', 'numeric_unused', 'sys_mapping', 'remapped_import', 'foreign_import', 'missing_import', 'invalid_import', 'list_mappings', 'partial_account', 'false_system', 'foreign_monitor', 'old_native_api', 'leaf_ingress', 'gateway_ingress'] as $case) {
            yield [$case, in_array($case, ['unchanged', 'numeric_unused'], true)];
        }
    }
    #[DataProvider('nativeRoutes')]
    public function testRouteProofMustDescribeUnchangedNativeApi(string $case, bool $accepted): void
    {
        $writes                = 0;
        $t                     = $this->transport(static function (string $suffix) use (&$writes): JsonObject {
            if ($suffix === 'INFO') {
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
            }
            if ($suffix === 'STREAM.INFO.S') {
                return new JsonObject(self::STREAM);
            }
            self::assertSame('STREAM.PEER.EVACUATE.S', $suffix);
            $writes++;
            return new JsonObject('{"type":"io.nats.jetstream.api.v1.stream_evacuate_peer_response","success":true}');
        });
        $this->diagnosticReply = static function ($message) use ($case) {
            $subject = $message->getSubject();
            $account = json_decode($message->getPayload(), true)['account'] ?? 'A';
            $reply   = json_decode(PlacementDiagnostics::response($subject, account: $account));
            if ($case === 'foreign_monitor') {
                $reply->server->id = 'OTHER';
            }
            if (str_ends_with($subject, '.VARZ')) {
                if ($case === 'old_native_api') {
                    $reply->data->jetstream->stats->api->level = 4;
                }
                if ($case === 'leaf_ingress') {
                    $reply->data->leaf = (object) ['port' => 7422];
                }
                if ($case === 'gateway_ingress') {
                    $reply->data->gateway = (object) ['name' => 'OUT'];
                }
            }
            if (str_ends_with($subject, '.ACCOUNTZ')) {
                $detail = $reply->data->account_detail;
                if ($case === 'numeric_unused') {
                    $detail->mappings = (object) ['123' => 'unused', '0' => 'unused', '00' => 'unused'];
                }
                if ($account === 'SYS') {
                    if ($case === 'sys_mapping') {
                        $detail->mappings = (object) ['$JS.API.STREAM.PEER.EVACUATE.S' => 'OTHER'];
                    }
                    if ($case === 'false_system') {
                        $detail->is_system = false;
                    }
                } else {
                    if ($case === 'remapped_import') {
                        $detail->imports[0]->subject = '$JS.API.STREAM.PEER.EVACUATE.OTHER';
                    }
                    if ($case === 'foreign_import') {
                        $detail->imports[0]->account = 'OTHER';
                    }
                    if ($case === 'missing_import') {
                        $detail->imports = [];
                    }
                    if ($case === 'invalid_import') {
                        $detail->imports[0]->invalid = true;
                    }
                    if ($case === 'list_mappings') {
                        $detail->mappings = [];
                    }
                    if ($case === 'partial_account') {
                        $detail->complete = false;
                    }
                }
            }
            return new \Dorpmaster\Nats\Protocol\MsgMessage('reply', '1', json_encode($reply, JSON_THROW_ON_ERROR));
        };
        try {
            (new JetStreamAdmin($t))->evacuateStreamPeer('S', 'n2');
            self::assertTrue($accepted);
        } catch (JetStreamPlacementException $error) {
            self::assertFalse($accepted);
            self::assertFalse($error->isOutcomeUnknown());
        }
        self::assertSame($accepted ? 1 : 0, $writes);
    }
    public static function placementShapes(): iterable
    {
        foreach (['[]', 'false', '"bad"', '42', 'null'] as $token) {
            foreach (['baseline', 'result'] as $stage) {
                yield $token . '/' . $stage => [$token, $stage];
            }
        }
    }
    #[DataProvider('placementShapes')]
    public function testRollbackRejectsPresentNonObjectPlacement(string $token, string $stage): void
    {
        $raw         = new JsonObject(self::STREAM);
        $change      = $raw->withFields(['cluster' => ['leader' => 'n1', 'desired' => ['origin' => ['replicas' => 3, 'retention' => 'limits']]]]);
        $invalid     = $raw->withFields(['config' => json_decode('{"name":"S","num_replicas":3,"retention":"limits","placement":' . $token . '}', false, 512, JSON_THROW_ON_ERROR)]);
        $originalRaw = $stage === 'baseline' ? $invalid : $raw;
        $original    = new StreamInfo('S', [], $originalRaw->getObject('config'), $originalRaw);
        $transition  = new StreamInfo('S', [], $change->getObject('config'), $change);
        $reads       = 0;
        $t           = $this->transport(static function () use ($stage, $invalid, $raw, &$reads): JsonObject {
            $reads++;
            return $stage === 'result' ? $invalid : $raw;
        });
        try {
            (new JetStreamAdmin($t))->waitForStreamRollback($transition, $original, 200);
            self::fail('A present non-object placement cannot confirm rollback');
        } catch (JetStreamApiException $error) {
            self::assertStringContainsString('config.placement', $error->getDescription());
        }
        self::assertSame($stage === 'baseline' ? 0 : 1, $reads);
    }
    public static function validPlacementShapes(): iterable
    {
        yield [false];
        yield [true];
    }
    #[DataProvider('validPlacementShapes')]
    public function testRollbackAcceptsAbsentOrObjectPlacement(bool $emptyObject): void
    {
        $raw = new JsonObject(self::STREAM);
        if ($emptyObject) {
            $config = $raw->getObject('config')->withFields(['placement' => new \stdClass()]);
            $raw    = $raw->withFields(['config' => json_decode($config->toJson(), false, 512, JSON_THROW_ON_ERROR)]);
        }
        $change     = $raw->withFields(['cluster' => ['leader' => 'n1', 'desired' => ['origin' => ['replicas' => 3, 'retention' => 'limits']]]]);
        $original   = new StreamInfo('S', [], $raw->getObject('config'), $raw);
        $transition = new StreamInfo('S', [], $change->getObject('config'), $change);
        $reads      = 0;
        $t          = $this->transport(static function () use ($raw, &$reads): JsonObject {
            $reads++;
            return $raw;
        });
        $result     = (new JetStreamAdmin($t))->waitForStreamRollback($transition, $original, 200);
        self::assertSame('S', $result->getName());
        self::assertSame($emptyObject, $result->getConfiguration()->has('placement'));
        self::assertSame(1, $reads);
    }
    public static function numericPeerGroups(): iterable
    {
        yield 'same exact strings, new leader' => [false];
        yield 'numerically equal replacement' => [true];
    }
    #[DataProvider('numericPeerGroups')]
    public function testRollbackPeerNamesAreAnExactStringSet(bool $replacement): void
    {
        $raw        = (new JsonObject(self::STREAM))->withFields(['cluster' => ['raft_group' => 'R3', 'leader' => '001', 'replicas' => [['name' => '0001', 'current' => true], ['name' => '1', 'current' => true]]]]);
        $change     = $raw->withFields(['cluster' => ['raft_group' => 'R3', 'leader' => '001', 'desired' => ['origin' => ['replicas' => 3, 'retention' => 'limits']]]]);
        $next       = $raw->withFields(['cluster' => ['raft_group' => 'R3', 'leader' => '1', 'replicas' => [['name' => '0001', 'current' => true], ['name' => $replacement ? '00001' : '001', 'current' => true]]]]);
        $t          = $this->transport(static fn (): JsonObject => $next);
        $admin      = new JetStreamAdmin($t);
        $original   = new StreamInfo('S', [], $raw->getObject('config'), $raw);
        $transition = new StreamInfo('S', [], $change->getObject('config'), $change);
        if ($replacement) {
            $this->expectException(JetStreamTimeoutException::class);
        }
        $result = $admin->waitForStreamRollback($transition, $original, 20);
        self::assertSame('1', $result->getCluster()->leader);
    }
    public static function selectedRoutes(): iterable
    {
        yield ['cancel', '$JS.API.STREAM.PEER.EVACUATE.S', true];
        yield ['stream', '$JS.API.STREAM.CANCEL_MOVE.S', true];
        yield ['consumer', '$JS.API.STREAM.INFO.S', true];
        yield ['consumer', '$JS.API.CONSUMER.INFO.S.C', false];
        yield ['cancel', '$JS.API.STREAM.INFO.S', false];
        yield ['cancel', '$JS.API.INFO', false];
        yield ['server', '$JS.API.INFO', true];
        yield ['server', '$JS.API.STREAM.PEER.EVACUATE.S', true];
    }
    #[DataProvider('selectedRoutes')]
    public function testOnlySelectedCommandAndItsAdmissionInfoAreProtected(string $operation, string $mapped, bool $accepted): void
    {
        $writes                = 0;
        $t                     = $this->transport(static function (string $suffix) use ($operation, &$writes): JsonObject {
            if ($suffix === 'INFO') {
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
            }
            if ($suffix === 'STREAM.INFO.S') {
                return new JsonObject(self::STREAM);
            }
            if ($suffix === 'CONSUMER.INFO.S.C') {
                return new JsonObject(self::CONSUMER);
            }
            $writes++;
            $type = match ($operation) {
                'cancel' => 'stream_cancel_move_response', 'stream' => 'stream_evacuate_peer_response',
                'consumer' => 'consumer_evacuate_peer_response', 'server' => 'meta_server_evacuate_response',
            };
            return new JsonObject($operation === 'cancel' ? str_replace('stream_info_response', $type, self::STREAM) : '{"type":"io.nats.jetstream.api.v1.' . $type . '","success":true}');
        });
        $this->diagnosticReply = static function ($message) use ($mapped, $operation) {
            $account = json_decode($message->getPayload(), true)['account'] ?? ($operation === 'server' ? 'SYS' : 'A');
            $raw     = PlacementDiagnostics::response($message->getSubject(), mappings: [$mapped => 'OTHER'], account: $account);
            return new \Dorpmaster\Nats\Protocol\MsgMessage('reply', '1', $raw);
        };
        try {
            self::invoke(new JetStreamAdmin($t), $operation);
            self::assertTrue($accepted);
        } catch (JetStreamPlacementException $error) {
            self::assertFalse($accepted);
            self::assertFalse($error->isOutcomeUnknown());
        }
        self::assertSame($accepted ? 1 : 0, $writes);
    }
}
