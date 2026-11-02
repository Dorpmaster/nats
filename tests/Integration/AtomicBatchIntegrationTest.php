<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Dorpmaster\Nats\Domain\JetStream\Exception\AtomicBatchException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchCommitMode;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneHeadersRequestMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\AtomicBatchFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class AtomicBatchIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function modes(): iterable
    {
        foreach (AtomicBatchCommitMode::cases() as $mode) {
            foreach ([1, 3] as $count) {
                yield [$mode, $count];
            }
        }
    }

    #[DataProvider('modes')]
    public function testAtomicVisibilityAndData(AtomicBatchCommitMode $mode, int $count): void
    {
        $this->enabled();
        $this->setTimeout(20);
        $this->runAsyncTest(function () use ($mode, $count): void {
            $fixture = new AtomicBatchFixture();
            try {
                $bag      = new HeaderBag(['X' => ['NATS/1.0', 'https://example.test/NATS/a:b']]);
                $messages = [];
                for ($index = 0; $index < $count; $index++) {
                    $messages[] = ['subject' => $fixture->subject . ($index % 2 === 0 ? '.a' : '.b'), 'payload' => $index === 0 ? '' : "synthetic-atomic-" . $index . "\r\n\0\xff", 'headers' => $bag];
                }
                if ($mode === AtomicBatchCommitMode::EOB && getenv('NATS_EXPECTED_VERSION') === '2.12.6') {
                    try {
                        $fixture->publisher->publishAtomicBatch($fixture->stream, $messages, new AtomicBatchOptions($mode));
                        self::fail('Known 2.12.6 cannot use eob');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(0, $error->getApiCode());
                    }
                    self::assertSame([], $fixture->probe->records);
                    self::assertSame([], $fixture->read());
                    $this->trace('unsupported-eob-' . $count, $fixture, []);
                    return;
                }
                $visibility             = [];
                $fixture->probe->before = function ($message) use ($fixture, &$visibility): void {
                    $rows = $fixture->read();
                    self::assertSame([], $rows, 'No user data may become visible before commit');
                    $visibility[] = ['before_sequence' => $message->getHeaders()->get('Nats-Batch-Sequence'), 'messages' => $rows];
                };
                $fixture->probe->after  = function ($message, $response) use ($fixture, &$visibility, $bag): void {
                    if ($message->getHeaders()->get('Nats-Batch-Commit') === null) {
                        self::assertSame('', $response->getPayload());
                        self::assertSame([], $fixture->read(), 'Zero-byte ACK is staging only');
                        $visibility[] = ['after_staging_ack' => $message->getHeaders()->get('Nats-Batch-Sequence'), 'messages' => []];
                        $bag->set('X', 'mutated-after-intermediate-ack');
                    }
                };
                $ack                    = $fixture->publisher->publishAtomicBatch($fixture->stream, $messages, new AtomicBatchOptions($mode), 5000);
                self::assertSame($fixture->stream, $ack->getStream());
                self::assertSame($count, $ack->getCount());
                self::assertSame((string) $count, $ack->getSeq());
                $rows = $fixture->read();
                self::assertCount($count, $rows);
                foreach ($rows as $index => $row) {
                    self::assertSame($messages[$index]['subject'], $row['subject']);
                    self::assertSame(bin2hex($messages[$index]['payload']), $row['payload_hex']);
                    $headers = base64_decode($row['headers_base64'], true);
                    self::assertStringContainsString("X: NATS/1.0\r\nX: https://example.test/NATS/a:b\r\n", $headers);
                }
                $this->assertWire($fixture, $count, $mode);
                $this->trace('visibility-' . $mode->value . '-' . $count, $fixture, ['visibility_before_commit' => $visibility, 'after_commit' => $rows, 'ack' => $ack->getResponse()->toJson()]);
                self::assertSame((string) $count, $fixture->reader->admin->getStreamInfo($fixture->stream)->getState()->messages);
            } finally {
                $fixture->close();
            }
        });
    }

    public static function rejections(): iterable
    {
        yield 'atomic disabled' => ['disabled', 10174];
        yield 'sequence gap' => ['gap', 10176];
        yield 'optimistic conflict' => ['conflict', 10071];
    }

    #[DataProvider('rejections')]
    public function testAtomicServerRejectionLeavesNoData(string $kind, int $errorCode): void
    {
        $this->enabled();
        $this->setTimeout(20);
        $this->runAsyncTest(function () use ($kind, $errorCode): void {
            $fixture = new AtomicBatchFixture($kind !== 'disabled');
            try {
                $messages = array_fill(0, 3, ['subject' => $fixture->subject . '.a', 'payload' => 'synthetic-rejected']);
                if ($kind === 'conflict') {
                    $messages[0]['headers'] = ['Nats-Expected-Last-Sequence' => '99'];
                }
                if ($kind === 'gap') {
                    $fixture->probe->before = static function ($message, int $index) {
                        if ($index !== 1) {
                            return $message;
                        }
                        $headers = $message->getHeaders();
                        $headers->set('Nats-Batch-Sequence', '3');
                        return new JetStreamControlPlaneHeadersRequestMessage($message->getSubject(), $message->getPayload(), $headers, $message->getReplyTo());
                    };
                }
                try {
                    $fixture->publisher->publishAtomicBatch($fixture->stream, $messages, timeoutMs: 5000);
                    self::fail('Expected server rejection');
                } catch (AtomicBatchException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertSame(400, $error->getApiCode());
                    self::assertSame($errorCode, $error->getErrorCode());
                    self::assertNotEmpty($error->getDescription());
                    $failure = ['code' => $error->getApiCode(), 'err_code' => $error->getErrorCode(), 'description' => $error->getDescription()];
                }
                self::assertSame([], $fixture->read());
                self::assertCount($kind === 'disabled' ? 0 : ($kind === 'gap' ? 2 : 3), $fixture->probe->records);
                $this->trace('rejection-' . $kind, $fixture, ['error' => $failure, 'stream_contents' => []]);
            } finally {
                $fixture->close();
            }
        });
    }

    public function testAtomicAccountMappingIsRejectedBeforeData(): void
    {
        $this->enabled();
        $this->setTimeout(20);
        $this->runAsyncTest(function (): void {
            $fixture      = new AtomicBatchFixture();
            $mappedStream = 'N4_MAPPED_' . strtoupper(bin2hex(random_bytes(5)));
            try {
                $fixture->writer->admin->createStream(new \Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig($mappedStream, ['n4.mapped'], 'memory', allowAtomicPublish: true));
                try {
                    $fixture->publisher->publishAtomicBatch($mappedStream, [['subject' => 'n4.mapped', 'payload' => 'first'], ['subject' => 'n4.mapped', 'payload' => 'second']], timeoutMs: 5000);
                    self::fail('A known matching mapping must be rejected');
                } catch (AtomicBatchException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertStringContainsString('subject mapping', $error->getDescription());
                }
                self::assertSame([], $fixture->probe->records);
                self::assertSame('0', $fixture->reader->admin->getStreamInfo($mappedStream)->getState()->messages);
                $this->trace('mapping-local', $fixture, ['batch_frames' => 0, 'stored' => 0, 'mapped_stream' => $mappedStream]);
            } finally {
                $fixture->writer->admin->deleteStream($mappedStream);
                $fixture->close();
            }
        });
    }

    public static function numericMappingCases(): iterable
    {
        yield 'unrelated numeric mapping' => [false];
        yield 'matching numeric mapping' => [true];
    }

    #[DataProvider('numericMappingCases')]
    public function testAtomicNumericMappingUsesExactStringSubject(bool $matching): void
    {
        $this->enabled();
        $this->setTimeout(20);
        $this->runAsyncTest(function () use ($matching): void {
            $fixture            = new AtomicBatchFixture();
            $source             = 'N4_NUMERIC_' . strtoupper(bin2hex(random_bytes(5)));
            $destination        = 'N4_WIRE_' . strtoupper(bin2hex(random_bytes(5)));
            $sourceCreated      = false;
            $destinationCreated = false;
            try {
                $fixture->writer->admin->createStream(new \Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig($destination, ['n4.wire'], 'memory', allowAtomicPublish: true));
                $destinationCreated = true;
                if ($matching) {
                    $fixture->writer->admin->createStream(new \Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig($source, ['123'], 'memory', allowAtomicPublish: true));
                    $sourceCreated = true;
                } else {
                    $source = $fixture->stream;
                }
                $messages = [['subject' => $matching ? '123' : $fixture->subject . '.a', 'payload' => 'first'], ['subject' => $matching ? '123' : $fixture->subject . '.b', 'payload' => 'second']];
                try {
                    $ack = $fixture->publisher->publishAtomicBatch($source, $messages, timeoutMs: 5000);
                    self::assertFalse($matching);
                    self::assertSame(2, $ack->getCount());
                    self::assertSame('2', $ack->getSeq());
                } catch (AtomicBatchException $error) {
                    self::assertTrue($matching);
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertStringContainsString('subject mapping may redirect or copy', $error->getDescription());
                }
                $frames  = $fixture->probe->records;
                $commits = count(array_filter($frames, static fn ($record): bool => isset($record['headers']['Nats-Batch-Commit'])));
                self::assertCount($matching ? 0 : 2, $frames);
                self::assertSame($matching ? 0 : 1, $commits);
                $mappingFound = false;
                foreach ($fixture->probe->checks as $check) {
                    if (str_ends_with($check['subject'], '.ACCOUNTZ')) {
                        $mappings = json_decode($check['response'], false, 512, JSON_THROW_ON_ERROR)->data->account_detail->mappings;
                        self::assertInstanceOf(\stdClass::class, $mappings);
                        self::assertTrue(property_exists($mappings, '123'));
                        self::assertSame('n4.wire', $mappings->{'123'}[0]->subject);
                        $mappingFound = true;
                    }
                }
                self::assertTrue($mappingFound, 'The actual server must confirm the numeric mapping');
                $rows = $matching ? [] : $fixture->read();
                if (!$matching) {
                    self::assertSame([bin2hex('first'), bin2hex('second')], array_column($rows, 'payload_hex'));
                    self::assertSame(array_column($messages, 'subject'), array_column($rows, 'subject'));
                }
                foreach ($matching ? [$source, $destination] : [$destination] as $emptyStream) {
                    self::assertSame('0', $fixture->reader->admin->getStreamInfo($emptyStream)->getState()->messages);
                    try {
                        $fixture->reader->transport->requestJson('STREAM.MSG.GET.' . $emptyStream, new JsonObject('{"seq":1}'));
                        self::fail('A rejected numeric mapping must leave both streams empty');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(404, $error->getApiCode());
                        self::assertSame(10037, $error->getErrorCode());
                    }
                }
                self::assertSame($matching ? '0' : '2', $fixture->writer->admin->getStreamInfo($source)->getState()->messages);
                $this->trace('numeric-mapping-' . ($matching ? 'matching' : 'unrelated'), $fixture, ['source' => $source, 'destination' => $destination, 'frames' => count($frames), 'commits' => $commits, 'source_messages' => $matching ? 0 : 2, 'destination_messages' => 0, 'rows' => $rows, 'next_request_success' => true]);
            } finally {
                try {
                    if ($sourceCreated) {
                        $fixture->writer->admin->deleteStream($source);
                    }
                    if ($destinationCreated) {
                        $fixture->writer->admin->deleteStream($destination);
                    }
                } finally {
                    $fixture->close();
                }
            }
        });
    }

    public static function intermediaryTimeouts(): iterable
    {
        yield 'HMSG 504' => [504];
        yield 'JSON 408' => [408];
    }

    #[DataProvider('intermediaryTimeouts')]
    public function testAtomicGatewayTimeoutAfterStoredCommitIsUnknown(int $code): void
    {
        $this->enabled();
        $this->setTimeout(20);
        $this->runAsyncTest(function () use ($code): void {
            $fixture = new AtomicBatchFixture();
            try {
                $realAck               = null;
                $fixture->probe->after = static function ($message, $response) use (&$realAck, $code) {
                    if ($message->getHeaders()->get('Nats-Batch-Commit') === null) {
                        return $response;
                    }
                    $realAck = $response->getPayload();
                    return $code === 408
                        ? new \Dorpmaster\Nats\Protocol\MsgMessage($message->getReplyTo(), '1', '{"error":{"code":408,"description":"Request Timeout"}}')
                        : new \Dorpmaster\Nats\Protocol\HMsgMessage($message->getReplyTo(), '1', '', new HeaderBag(), status: new \Dorpmaster\Nats\Protocol\MessageStatus(504, 'Gateway Timeout'));
                };
                try {
                    $fixture->publisher->publishAtomicBatch($fixture->stream, [['subject' => $fixture->subject . '.a', 'payload' => 'first'], ['subject' => $fixture->subject . '.b', 'payload' => 'second']]);
                    self::fail('Gateway timeout cannot establish a commit rejection');
                } catch (AtomicBatchException $error) {
                    self::assertTrue($error->isOutcomeUnknown());
                    self::assertSame($code, $error->getApiCode());
                    self::assertSame($code === 408 ? 'Request Timeout' : 'Gateway Timeout', $error->getDescription());
                    self::assertSame($fixture->probe->records[0]['headers']['Nats-Batch-Id'][0], $error->getBatchId());
                    self::assertSame($code, $error->getPrevious()->getApiCode());
                }
                self::assertCount(2, $fixture->probe->records);
                $commits = array_filter($fixture->probe->records, static fn ($record): bool => isset($record['headers']['Nats-Batch-Commit']));
                self::assertCount(1, $commits);
                $rows = $fixture->read();
                self::assertSame([bin2hex('first'), bin2hex('second')], array_column($rows, 'payload_hex'));
                $this->trace('gateway-timeout-' . $code, $fixture, ['real_ack' => $realAck, 'injected_status' => $code, 'outcome_unknown' => true, 'commit_count' => 1, 'after_commit' => $rows]);
            } finally {
                $fixture->close();
            }
        });
    }

    public static function isolationModes(): iterable
    {
        yield [false];
        yield [true];
    }

    #[DataProvider('isolationModes')]
    public function testAtomicParallelOperationsAreIndependent(bool $cancel): void
    {
        $this->enabled();
        $this->setTimeout(20);
        $this->runAsyncTest(function () use ($cancel): void {
            $fixture  = new AtomicBatchFixture();
            $stagedA  = new DeferredFuture();
            $stagedB  = new DeferredFuture();
            $releaseA = new DeferredFuture();
            $releaseB = new DeferredFuture();
            $source   = new DeferredCancellation();
            try {
                $fixture->probe->after = static function ($message) use ($stagedA, $stagedB, $releaseA, $releaseB): void {
                    if ($message->getHeaders()->get('Nats-Batch-Sequence') !== '1') {
                        return;
                    }
                    if ($message->getPayload() === 'synthetic-A') {
                        $stagedA->complete();
                        $releaseA->getFuture()->await();
                    } elseif ($message->getPayload() === 'synthetic-B1') {
                        $stagedB->complete();
                        $releaseB->getFuture()->await();
                    }
                };
                $a                     = [['subject' => $fixture->subject . '.a', 'payload' => 'synthetic-A', 'headers' => $cancel ? [] : ['Nats-Expected-Last-Sequence' => '99']], ['subject' => $fixture->subject . '.a', 'payload' => 'synthetic-A']];
                $first                 = async(static fn () => $fixture->publisher->publishAtomicBatch($fixture->stream, $a, new AtomicBatchOptions(cancellation: $source->getCancellation()), 5000));
                $stagedA->getFuture()->await();
                $b      = [['subject' => $fixture->subject . '.b', 'payload' => 'synthetic-B1'], ['subject' => $fixture->subject . '.b', 'payload' => 'synthetic-B2']];
                $second = async(static fn () => $fixture->publisher->publishAtomicBatch($fixture->stream, $b, timeoutMs: 5000));
                $stagedB->getFuture()->await();
                self::assertSame([], $fixture->read());
                self::assertFalse($first->isComplete());
                self::assertFalse($second->isComplete());
                if ($cancel) {
                    $source->cancel();
                }
                $releaseA->complete();
                try {
                    $first->await();
                    self::fail('A must fail independently');
                } catch (AtomicBatchException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                }
                self::assertFalse($second->isComplete(), 'A failure must not complete the waiting B operation');
                self::assertSame([], $fixture->read());
                $releaseB->complete();
                $ack = $second->await();
                $ids = array_unique(array_map(static fn ($record) => $record['headers']['Nats-Batch-Id'][0], $fixture->probe->records));
                self::assertCount(2, $ids);
                self::assertSame(2, $ack->getCount());
                $rows = $fixture->read();
                self::assertSame([bin2hex('synthetic-B1'), bin2hex('synthetic-B2')], array_column($rows, 'payload_hex'));
                $this->trace('isolation-' . ($cancel ? 'cancel' : 'error'), $fixture, ['contents' => $rows, 'batch_ids' => array_values($ids), 'B_waited_through_A_failure' => true]);
            } finally {
                if (!$releaseA->isComplete()) {
                    $releaseA->complete();
                }
                if (!$releaseB->isComplete()) {
                    $releaseB->complete();
                }
                $fixture->close();
            }
        });
    }

    public function testConfirmedBatchSurvivesLeaderChange(): void
    {
        if (getenv('NATS_ATOMIC_CLUSTER') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-atomic-cluster.');
        }
        $this->setTimeout(60);
        $this->runAsyncTest(function (): void {
            $fixture = new AtomicBatchFixture(replicas: 3);
            try {
                $messages = [];
                for ($i = 0; $i < 3; $i++) {
                    $messages[] = ['subject' => $fixture->subject . '.' . $i, 'payload' => "R3-" . $i . "\r\n\0"];
                }
                $ack    = $fixture->publisher->publishAtomicBatch($fixture->stream, $messages, timeoutMs: 5000);
                $before = $fixture->reader->admin->getStreamInfo($fixture->stream);
                $leader = $before->getCluster()->leader;
                self::assertCount(2, $before->getCluster()->replicas);
                $fixture->writer->transport->requestJson('STREAM.LEADER.STEPDOWN.' . $fixture->stream, new JsonObject('{}'), 5000);
                $deadline = hrtime(true) + 20_000_000_000;
                do {
                    delay(0.05);
                    try {
                        $after = $fixture->reader->admin->getStreamInfo($fixture->stream);
                    } catch (JetStreamApiException) {
                        $after = null;
                    }
                    self::assertLessThan($deadline, hrtime(true));
                } while ($after?->getCluster()?->leader === null || $after->getCluster()->leader === $leader);
                self::assertSame('3', $ack->getSeq());
                $rows = $fixture->read();
                self::assertSame(array_map(static fn ($message) => bin2hex($message['payload']), $messages), array_column($rows, 'payload_hex'));
                self::assertSame(array_column($messages, 'subject'), array_column($rows, 'subject'));
                $this->trace('replicated', $fixture, ['leader_before' => $leader, 'leader_after' => $after->getCluster()->leader, 'after' => $rows, 'ack' => $ack->getResponse()->toJson()]);
            } finally {
                $fixture->close();
            }
        });
    }

    private function enabled(): void
    {
        if (getenv('NATS_ATOMIC') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-atomic.');
        }
    }

    private function assertWire(AtomicBatchFixture $fixture, int $count, AtomicBatchCommitMode $mode): void
    {
        self::assertCount($count + ($mode === AtomicBatchCommitMode::EOB ? 1 : 0), $fixture->probe->records);
        $id = null;
        foreach ($fixture->probe->records as $index => $record) {
            $headers = $record['headers'];
            $id    ??= $headers['Nats-Batch-Id'][0];
            self::assertSame($id, $headers['Nats-Batch-Id'][0]);
            self::assertLessThanOrEqual(64, strlen($id));
            self::assertSame((string) ($index + 1), $headers['Nats-Batch-Sequence'][0]);
        }
    }

    private function trace(string $name, AtomicBatchFixture $fixture, array $observations): void
    {
        self::assertStringNotContainsString('synthetic-atomic-', serialize($fixture->writer->logs));
        self::assertStringNotContainsString('synthetic-n3-password', serialize($fixture->writer->logs));
        $directory = getenv('NATS_ATOMIC_TRACE_DIR');
        if ($directory !== false && $directory !== '') {
            file_put_contents($directory . '/' . $name . '.json', json_encode(['version' => $fixture->writer->client->getServerInformation()->version, 'frames' => $fixture->probe->records, 'checks' => $fixture->probe->checks, 'independent_empty_reads' => $fixture->emptyReads, 'observations' => $observations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
