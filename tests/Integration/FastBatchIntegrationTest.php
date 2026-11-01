<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\JetStream\Exception\FastBatchException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Publish\FastBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\FastBatchFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FastBatchIntegrationTest extends TestCase
{
    use AsyncTestTools;

    private function enabled(): void
    {
        if (getenv('NATS_FAST') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-fast.');
        }
        $this->setTimeout(40);
    }
    public static function modes(): iterable
    {
        yield 'single commit' => ['single'];
        yield 'last message' => ['message'];
        yield 'EOB' => ['eob'];
    }
    #[DataProvider('modes')]
    public function testFastDataAndVisibility(string $mode): void
    {
        $this->enabled();
        $this->runAsyncTest(function () use ($mode): void {
            $f = new FastBatchFixture();
            try {
                $publisher = new JetStreamPublisher($f->probe);
                if (getenv('NATS_EXPECTED_VERSION') === '2.12.6') {
                    try {
                        $publisher->openFastBatch($f->stream, $f->subjects);
                        self::fail('Old API must fail locally');
                    } catch (JetStreamApiException) {
                        self::assertSame(0, $f->probe->dataFrames);
                        self::assertSame([], $f->read());
                    }
                    return;
                }
                $session = $publisher->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1), 5000);
                $bag     = new HeaderBag(['X' => ['NATS/1.0', 'https://example.test/a:b']]);
                $before  = [];
                if ($mode !== 'single') {
                    $session->publish($f->subjects[0], "first\r\n\0", $bag);
                    $session->awaitAcknowledged('1');
                    $before = $f->read();
                    self::assertCount(1, $before, 'Fast messages must be readable before commit');
                    $bag->set('X', 'changed-after-write');
                }
                $result = $mode === 'eob' ? $session->finish() : $session->commit($f->subjects[1], '', $mode === 'single' ? $bag : []);
                self::assertTrue($result->isComplete());
                self::assertFalse($result->isOutcomeUnknown());
                self::assertSame($mode === 'single' ? '1' : ($mode === 'eob' ? '1' : '2'), $result->getFinalAck()->getCount());
                self::assertSame(1, $f->probe->commits);
                $rows = $f->read();
                self::assertCount($mode === 'message' ? 2 : 1, $rows);
                self::assertSame($mode === 'single' ? '' : bin2hex("first\r\n\0"), $rows[0]['payload_hex']);
                self::assertStringContainsString("X: NATS/1.0\r\nX: https://example.test/a:b\r\n", base64_decode($rows[0]['headers_base64'], true));
                self::assertSame(0, $session->getProgress()->getPendingBytes());
                $this->trace($mode, $f, ['before_commit' => $before, 'after_commit' => $rows, 'ack' => $result->getFinalAck()->getResponse()->toJson()]);
            } finally {
                $f->close();
            }
        });
    }
    public static function expiredOperations(): iterable
    {
        yield ['publish'];
        yield ['commit'];
        yield ['finish'];
    }
    #[DataProvider('expiredOperations')]
    public function testFastExpiredDeadlineCannotWrite(string $operation): void
    {
        $this->enabled();
        $this->runAsyncTest(function () use ($operation): void {
            $f = new FastBatchFixture();
            try {
                if (!$this->supported($f)) {
                    return;
                }
                $s = (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1), 600);
                if ($operation !== 'commit') {
                    $s->publish($f->subjects[0], 'before');
                    $s->awaitAcknowledged('1');
                }
                $frames = count($f->probe->records);
                usleep(700000); // Application monopolizes PHP beyond the session deadline.
                $error = null;
                try {
                    $operation === 'finish' ? $s->finish() : $s->$operation($f->subjects[0], 'after');
                } catch (FastBatchException $failure) {
                    $error = $failure;
                }
                $rows = $f->read();
                $this->trace('expired-' . $operation, $f, ['frames_before' => $frames, 'frames_after' => count($f->probe->records), 'error_code' => $error?->getApiCode(), 'rows' => $rows]);
                self::assertInstanceOf(FastBatchException::class, $error);
                self::assertSame(408, $error->getApiCode());
                self::assertCount($frames, $f->probe->records);
                self::assertFalse($error->getResult()->isOutcomeUnknown());
                self::assertSame($operation === 'commit' ? [] : [bin2hex('before')], array_column($rows, 'payload_hex'));
                $f->admin->getStreamInfo($f->stream);
            } finally {
                $f->close();
            }
        });
    }
    public function testFastConstructorCannotBypassAdmission(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new FastBatchFixture(false);
            try {
                $error = null;
                try {
                    $s = new \Dorpmaster\Nats\Domain\JetStream\Publish\FastBatchSession($f->probe, $f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1), hrtime(true) + 1000000000, new \Amp\TimeoutCancellation(1), bin2hex(random_bytes(16)), $f->probe->getServerInformation()->connectionId);
                    $s->commit($f->subjects[0], 'one');
                } catch (\Throwable $failure) {
                    $error = $failure;
                }
                $rows = $f->read();
                $this->trace('constructor-guard', $f, ['error_type' => $error === null ? null : $error::class, 'frames' => $f->probe->dataFrames, 'rows' => $rows]);
                self::assertInstanceOf(\Error::class, $error);
                self::assertStringContainsString('private', $error->getMessage());
                foreach (['publisher', 'session factory'] as $factory) {
                    try {
                        $factory === 'publisher' ? (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects) : \Dorpmaster\Nats\Domain\JetStream\Publish\FastBatchSession::open($f->probe, $f->stream, $f->subjects);
                        self::fail('Every public factory must enforce admission');
                    } catch (JetStreamApiException) {
                    }
                }
                self::assertSame(0, $f->probe->dataFrames);
                self::assertSame([], $rows);
                $f->admin->getStreamInfo($f->stream);
            } finally {
                $f->close();
            }
        });
    }
    public static function dedupModes(): iterable
    {
        yield ['eob'];
        yield ['message'];
    }
    #[DataProvider('dedupModes')]
    public function testFastDeduplicatedFinalPreservesZeroStreamSequence(string $mode): void
    {
        $this->enabled();
        $this->runAsyncTest(function () use ($mode): void {
            $f = new FastBatchFixture();
            try {
                if (!$this->supported($f)) {
                    return;
                }
                $publisher = new JetStreamPublisher($f->probe);
                $headers   = ['Nats-Msg-Id' => 'synthetic-N5-dedup'];
                (new JetStreamPublisher($f->writer))->publish($f->subjects[0], 'same', new \Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions(msgId: 'synthetic-N5-dedup'));
                $s = $publisher->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1), 5000);
                $s->publish($f->subjects[0], 'same', $headers);
                $s->awaitAcknowledged('1');
                $result = $mode === 'eob' ? $s->finish() : $s->commit($f->subjects[0], 'same', $headers);
                $rows   = $f->read();
                $this->trace('dedup-' . $mode, $f, ['complete' => $result->isComplete(), 'unknown' => $result->isOutcomeUnknown(), 'ack' => $result->getFinalAck()->getResponse()->toJson(), 'rows' => $rows]);
                self::assertTrue($result->isComplete());
                self::assertFalse($result->isOutcomeUnknown());
                self::assertSame('0', $result->getFinalAck()->getSeq());
                self::assertSame($mode === 'eob' ? '1' : '2', $result->getFinalAck()->getCount());
                self::assertSame(1, $f->probe->commits);
                self::assertCount(1, $rows);
                self::assertSame(bin2hex('same'), $rows[0]['payload_hex']);
                $f->admin->getStreamInfo($f->stream);
            } finally {
                $f->close();
            }
        });
    }
    public static function settlementCases(): iterable
    {
        foreach (['finish', 'awaitAcknowledged'] as $operation) {
            foreach (['before', 'after', 'lost'] as $order) {
                foreach ([false, true] as $confirmed) {
                    yield $operation . '-' . $order . '-ack' . (int) $confirmed => [$operation, $order, $confirmed];
                }
            }
        }
    }
    #[DataProvider('settlementCases')]
    public function testFastServerRejectionKeepsFinalPubAck(string $operation, string $order, bool $confirmed): void
    {
        $this->enabled();
        $this->runAsyncTest(function () use ($operation, $order, $confirmed): void {
            $f      = new FastBatchFixture();
            $buffer = (new \ReflectionProperty($f->writer, 'writeBuffer'))->getValue($f->writer);
            try {
                if (!$this->supported($f)) {
                    return;
                }
                $held             = [];
                $f->probe->filter = static function ($message, $deliver) use (&$held, $confirmed) {
                    $raw  = json_decode($message->getPayload(), true, flags: JSON_THROW_ON_ERROR);
                    $kind = $raw['type'] ?? 'final';
                    if ($kind === 'ack') {
                        return $raw['seq'] === 0 || $confirmed ? $message : false;
                    }
                    $held[$kind] = [$message, $deliver];
                    return false;
                };
                $wait             = static function (\Closure $predicate): void {
                    $limit = hrtime(true) + 2000000000;
                    while (!$predicate()) {
                        if (hrtime(true) >= $limit) {
                            throw new \RuntimeException('Fast settlement barrier timed out');
                        }
                        \Amp\delay(.001);
                    }
                };
                $started          = hrtime(true);
                $session          = (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1, ackTimeoutMs: 200), 2000);
                $session->publish($f->subjects[0], 'first');
                if ($confirmed) {
                    $session->awaitAcknowledged('1');
                }
                $session->publish($f->subjects[0], 'rejected', ['Nats-Expected-Last-Sequence' => '99']);
                $wait(static function () use (&$held): bool {
                    return isset($held['err'], $held['final']);
                });
                $buffer->pause();
                $pending = \Amp\async(fn () => $operation === 'finish' ? $session->finish() : $session->awaitAcknowledged('2'));
                $wait(fn (): bool => $buffer->getPendingMessages() >= 1);
                ($held['err'][1])($held['err'][0]);
                if ($order === 'before') {
                    ($held['final'][1])($held['final'][0]);
                }
                $buffer->resume();
                $wait(fn (): bool => $buffer->getPendingMessages() === 0);
                if ($order !== 'before') {
                    self::assertFalse($pending->isComplete(), 'A withdrawn control frame must not finalize the session');
                    self::assertNull($session->getResult());
                }
                if ($order === 'after') {
                    ($held['final'][1])($held['final'][0]);
                }
                try {
                    $pending->await();
                    self::fail('Rejected batch cannot become full success');
                } catch (FastBatchException $error) {
                    self::assertSame(400, $error->getApiCode());
                    self::assertSame(10071, $error->getErrorCode());
                    $result = $error->getResult();
                }
                self::assertFalse($result->isComplete());
                self::assertSame('2', $result->getProblemSequence());
                self::assertSame($order === 'lost' ? ($confirmed ? '1' : '0') : '1', $result->getLastAcknowledgedSequence());
                self::assertSame($order === 'lost' && !$confirmed ? [['from' => '1', 'to' => '1']] : [], $result->getUnknownRanges());
                self::assertSame($order === 'lost', $result->getFinalAck() === null);
                if ($order !== 'lost') {
                    self::assertSame('1', $result->getFinalAck()->getCount());
                }
                self::assertLessThan(2.5, (hrtime(true) - $started) / 1000000000);
                $rows = $f->read();
                self::assertSame([bin2hex('first')], array_column($rows, 'payload_hex'));
                self::assertSame([0, 1], $f->probe->attemptedOperations);
                $f->admin->getStreamInfo($f->stream);
                $this->trace('settlement-' . $operation . '-' . $order . '-ack' . (int) $confirmed, $f, ['attempted_operations' => $f->probe->attemptedOperations, 'server_error' => $held['err'][0]->getPayload(), 'server_final' => $held['final'][0]->getPayload(), 'final_received' => $result->getFinalAck() !== null, 'confirmed' => $result->getLastAcknowledgedSequence(), 'unknown_ranges' => $result->getUnknownRanges(), 'elapsed_ms' => (hrtime(true) - $started) / 1000000, 'rows' => $rows]);
            } finally {
                $buffer->resume();
                $f->close();
            }
        });
    }
    public function testFastDisabledHasNoData(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new FastBatchFixture(false);
            try {
                try {
                    (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects);
                    self::fail('Disabled batch cannot start');
                } catch (JetStreamApiException) {
                    self::assertSame(0, $f->probe->dataFrames);
                }
                self::assertSame([], $f->read());
            } finally {
                $f->close();
            }
        });
    }
    private function supported(FastBatchFixture $f): bool
    {
        if (getenv('NATS_EXPECTED_VERSION') !== '2.12.6') {
            return true;
        }
        try {
            (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects);
            self::fail('Old API must refuse');
        } catch (JetStreamApiException) {
            self::assertSame(0, $f->probe->dataFrames);
            self::assertSame([], $f->read());
        }
        return false;
    }
    public static function failures(): iterable
    {
        yield 'gap' => [true, false];
        yield 'gap notice lost' => [true, true];
        yield 'message error' => [false, false];
        yield 'error notice lost' => [false, true];
    }
    #[DataProvider('failures')]
    public function testFastFailureWaitsForPartialPubAck(bool $gap, bool $loseNotice): void
    {
        $this->enabled();
        $this->runAsyncTest(function () use ($gap, $loseNotice): void {
            $f = new FastBatchFixture();
            try {
                if (!$this->supported($f)) {
                    return;
                }
                $f->probe->filter = static function ($message) use ($loseNotice) {
                    $raw = json_decode($message->getPayload(), true);
                    return $loseNotice && in_array($raw['type'] ?? null, ['err', 'gap'], true) ? false : $message;
                };
                if ($gap) {
                    $f->probe->writeFilter = static function ($message) {
                        $parts = explode('.', $message->getReplyTo());
                        return $parts[count($parts) - 3] === '2' && $parts[count($parts) - 2] === '1' ? false : $message;
                    };
                }
                $session = (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1, pingTimeoutMs: 1000), 5000);
                $session->publish($f->subjects[0], 'first');
                $session->awaitAcknowledged('1');
                try {
                    $session->publish($f->subjects[0], 'failed', $gap ? [] : ['Nats-Expected-Last-Sequence' => '0']);
                    $session->publish($f->subjects[1], 'after');
                    $session->finish();
                    self::fail('A failed gap=fail batch cannot be successful');
                } catch (FastBatchException $error) {
                    $result = $error->getResult();
                    self::assertFalse($result->isComplete());
                    self::assertFalse($result->isOutcomeUnknown());
                    self::assertSame('1', $result->getLastAcknowledgedSequence());
                    self::assertSame('1', $result->getFinalAck()->getCount());
                    self::assertSame('2', $result->getProblemSequence());
                    if (!$gap && !$loseNotice) {
                        self::assertSame(400, $error->getApiCode());
                        self::assertSame(10071, $error->getErrorCode());
                    }
                    $observation = ['sent' => $result->getLastSentSequence(), 'confirmed' => $result->getLastAcknowledgedSequence(), 'code' => $error->getApiCode(), 'err_code' => $error->getErrorCode(), 'problem_sequence' => $result->getProblemSequence(), 'unknown' => $result->isOutcomeUnknown(), 'ack' => $result->getFinalAck()->getResponse()->toJson()];
                }
                $before = $f->probe->dataFrames;
                try {
                    $session->publish($f->subjects[0], 'late');
                    self::fail('Stopped session cannot send');
                } catch (FastBatchException) {
                }
                self::assertSame($before, $f->probe->dataFrames);
                $rows = $f->read();
                self::assertCount(1, $rows);
                self::assertSame(bin2hex('first'), $rows[0]['payload_hex']);
                $this->trace(($gap ? 'gap' : 'conflict') . ($loseNotice ? '-notice-lost' : ''), $f, $observation + ['stored' => $rows]);
            } finally {
                $f->close();
            }
        });
    }
    public function testFastPingRecoversLostAndIgnoresDelayedAck(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new FastBatchFixture();
            try {
                if (!$this->supported($f)) {
                    return;
                }
                $dropped          = false;
                $zero             = null;
                $f->probe->filter = static function ($message, $deliver) use (&$dropped, &$zero) {
                    $raw = json_decode($message->getPayload(), true);
                    if (($raw['type'] ?? null) === 'ack' && $raw['seq'] === 0) {
                        $zero = $message;
                    }
                    if (($raw['type'] ?? null) === 'ack' && $raw['seq'] === 1) {
                        if (!$dropped) {
                            $dropped = true;
                            return false;
                        }
                        if ($zero !== null) {
                            \Revolt\EventLoop::delay(.005, static fn () => $deliver($zero));
                        }
                    }
                    return $message;
                };
                $s                = (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1, ackTimeoutMs: 20, pingTimeoutMs: 500), 5000);
                $s->publish($f->subjects[0], 'first');
                $s->awaitAcknowledged('1');
                \Amp\delay(.02);
                self::assertSame('1', $s->getProgress()->getLastAcknowledgedSequence());
                self::assertSame(1, $f->probe->pings);
                self::assertTrue($s->finish()->isComplete());
                self::assertCount(1, $f->read());
                $this->trace('lost-and-delayed-ack', $f, ['ping_count' => $f->probe->pings, 'confirmed' => '1']);
            } finally {
                $f->close();
            }
        });
    }
    public function testFastParallelCancelAndWindowChanges(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new FastBatchFixture();
            try {
                if (!$this->supported($f)) {
                    return;
                }
                $p = new JetStreamPublisher($f->probe);
                $a = $p->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 8), 5000);
                $a->publish($f->subjects[0], 'A');
                $b = $p->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 8), 5000);
                $b->publish($f->subjects[1], 'B1');
                self::assertNotSame($a->getProgress()->getBatchId(), $b->getProgress()->getBatchId());
                $cancelled = $a->cancel();
                self::assertTrue($cancelled->isCancelled());
                self::assertNull($b->getResult());
                for ($index = 2; $index < 12; $index++) {
                    $b->publish($f->subjects[1], 'B' . $index);
                }
                $result = $b->commit($f->subjects[1], 'B12');
                self::assertTrue($result->isComplete());
                self::assertSame('12', $result->getLastAcknowledgedSequence());
                $rows = $f->read();
                self::assertCount(13, $rows);
                self::assertSame(bin2hex('A'), $rows[0]['payload_hex']);
                $windows = [];
                foreach ($f->probe->controls as $control) {
                    $raw = json_decode($control['payload'], true);
                    if (($raw['type'] ?? null) === 'ack' && str_contains($control['subject'], $result->getBatchId())) {
                        $windows[] = $raw['msgs'];
                    }
                }
                self::assertContains(1, $windows);
                self::assertGreaterThan(1, max($windows));
                $this->trace('parallel-window-change', $f, ['cancelled_id' => $cancelled->getBatchId(), 'complete_id' => $result->getBatchId(), 'windows' => $windows, 'stored' => $rows]);
            } finally {
                $f->close();
            }
        });
    }
    public function testFastLostFinalAckNeverMeansCompleteSuccess(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new FastBatchFixture();
            try {
                if (!$this->supported($f)) {
                    return;
                }
                $f->probe->filter = static function ($message) {
                    $raw = json_decode($message->getPayload(), true);
                    return isset($raw['batch']) ? false : $message;
                };
                $s                = (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1, pingTimeoutMs: 50), 5000);
                $s->publish($f->subjects[0], 'first');
                $s->awaitAcknowledged('1');
                try {
                    $s->finish();
                    self::fail('Missing final reply cannot be full success');
                } catch (FastBatchException $error) {
                    self::assertTrue($error->getResult()->isOutcomeUnknown());
                    self::assertFalse($error->getResult()->isComplete());
                    self::assertSame([], $error->getResult()->getUnknownRanges());
                }
                self::assertSame(1, $f->probe->commits);
                self::assertCount(1, $f->read());
                $f->writer->disconnect();
                $f->writer->connect();
                $f->admin->getStreamInfo($f->stream);
                self::assertSame(1, $f->probe->commits);
                self::assertSame('1', $f->readerAdmin->getStreamInfo($f->stream)->getState()->messages);
                $this->trace('lost-final', $f, ['commit_count' => 1, 'confirmed' => '1', 'unknown_ranges' => [], 'unknown_final_confirmation' => true]);
            } finally {
                $f->close();
            }
        });
    }
    public function testFastUnconfirmedSubjectHasNoFrames(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new FastBatchFixture();
            try {
                try {
                    (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, [$f->subjects[0], 'unrouted.' . bin2hex(random_bytes(5))]);
                    self::fail('All declared routes must be confirmed');
                } catch (JetStreamApiException) {
                    self::assertSame(0, $f->probe->dataFrames);
                    self::assertSame(0, $f->probe->commits);
                }
                self::assertSame([], $f->read());
            } finally {
                $f->close();
            }
        });
    }
    public function testFastLongStreamHasBoundedMemoryAndVisiblePrefix(): void
    {
        $this->enabled();
        $this->setTimeout(120);
        $this->runAsyncTest(function (): void {
            $f = new FastBatchFixture();
            try {
                if (!$this->supported($f)) {
                    return;
                }
                $s               = (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 16), 90000);
                $payload         = str_repeat('x', 1024);
                $baseline        = 0;
                $maximumBytes    = 0;
                $maximumMessages = 0;
                for ($index = 1; $index <= 20000; $index++) {
                    $p               = $s->publish($f->subjects[$index % 2], $payload);
                    $maximumBytes    = max($maximumBytes, $p->getPendingBytes());
                    $maximumMessages = max($maximumMessages, $p->getPendingMessages());
                    if ($index === 1000) {
                        gc_collect_cycles();
                        $baseline = memory_get_usage(true);
                    }
                }
                $s->awaitAcknowledged('20000');
                gc_collect_cycles();
                $growth = memory_get_usage(true) - $baseline;
                self::assertLessThanOrEqual(8 * 1024 * 1024, $growth);
                self::assertLessThanOrEqual(2097152, $maximumBytes);
                self::assertLessThanOrEqual(32, $maximumMessages);
                self::assertSame('20000', $f->readerAdmin->getStreamInfo($f->stream)->getState()->messages, 'All confirmed data is visible before commit');
                $result = $s->finish();
                self::assertTrue($result->isComplete());
                self::assertSame('20000', $result->getFinalAck()->getCount());
                $last = $f->readerTransport->requestJson('STREAM.MSG.GET.' . $f->stream, new \Dorpmaster\Nats\Domain\JetStream\Model\JsonObject('{"seq":20000}'))->getObject('message');
                self::assertSame($payload, base64_decode($last->get('data'), true));
                $this->trace('long-stream', $f, ['messages' => 20000, 'visible_before_commit' => 20000, 'allocator_growth_1000_to_20000' => $growth, 'max_pending_bytes' => $maximumBytes, 'max_pending_messages' => $maximumMessages]);
            } finally {
                $f->close();
            }
        });
    }
    public function testFastConfirmedPrefixSurvivesLeaderChange(): void
    {
        if (getenv('NATS_FAST_CLUSTER') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-fast-cluster.');
        }
        $this->setTimeout(60);
        $this->runAsyncTest(function (): void {
            $f = new FastBatchFixture(replicas: 3);
            try {
                $s = (new JetStreamPublisher($f->probe))->openFastBatch($f->stream, $f->subjects, new FastBatchOptions(initialFlow: 1), 30000);
                for ($i = 1; $i <= 3; $i++) {
                    $s->publish($f->subjects[$i % 2], 'R3-' . $i);
                }
                $s->awaitAcknowledged('3');
                $leader = $f->readerAdmin->getStreamInfo($f->stream)->getCluster()->leader;
                $f->readerTransport->requestJson('STREAM.LEADER.STEPDOWN.' . $f->stream, new \Dorpmaster\Nats\Domain\JetStream\Model\JsonObject('{}'), 5000);
                $deadline = hrtime(true) + 20_000_000_000;
                do {
                    \Amp\delay(.05);
                    try {
                        $next = $f->readerAdmin->getStreamInfo($f->stream)->getCluster()->leader;
                    } catch (JetStreamApiException) {
                        $next = null;
                    }
                } while (($next === null || $next === $leader) && hrtime(true) < $deadline);
                self::assertNotNull($next);
                self::assertNotSame($leader, $next);
                $rows = $f->read();
                self::assertSame([bin2hex('R3-1'), bin2hex('R3-2'), bin2hex('R3-3')], array_column($rows, 'payload_hex'));
                try {
                    $result = $s->finish();
                } catch (FastBatchException $error) {
                    $result = $error->getResult();
                }
                self::assertSame('3', $result->getLastAcknowledgedSequence());
                self::assertSame('3', $f->readerAdmin->getStreamInfo($f->stream)->getState()->messages);
                $this->trace('replicated', $f, ['leader_before' => $leader, 'leader_after' => $next, 'confirmed' => '3', 'complete' => $result->isComplete(), 'stored' => $rows]);
            } finally {
                $f->close();
            }
        });
    }
    private function trace(string $name, FastBatchFixture $fixture, array $observations): void
    {
        $directory = getenv('NATS_FAST_TRACE_DIR');
        if ($directory) {
            file_put_contents($directory . '/' . $name . '.json', json_encode(['checks' => $fixture->probe->checks, 'frames' => $fixture->probe->records, 'controls' => $fixture->probe->controls, 'observations' => $observations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
