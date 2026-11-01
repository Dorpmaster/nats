<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\JetStream\Exception\FastBatchException;
use Dorpmaster\Nats\Domain\JetStream\Publish\FastBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\FastBatchTcpHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class FastBatchWriteConformanceTest extends TestCase
{
    use AsyncTestTools;

    public static function breaks(): iterable
    {
        yield ['write'];
        yield ['disconnect'];
    }
    #[DataProvider('breaks')]
    public function testFrame80IsUnknownAndNeverReplayed(string $kind): void
    {
        $this->setTimeout(20);
        $this->runAsyncTest(function () use ($kind): void {
            $h     = new FastBatchTcpHarness();
            $cause = new ConnectionException('synthetic-N5-write-failure');
            try {
                $h->afterFrame = function ($frame) use ($h, $kind, $cause): void {
                    if ($frame['sequence'] === 80) {
                        if ($kind === 'write') {
                            throw $cause;
                        }
                        $h->server->closeSession(0);
                        return;
                    }
                    $h->ack($frame);
                };
                $s             = (new JetStreamPublisher($h->client))->openFastBatch('S', ['fast.a'], new FastBatchOptions(initialFlow: 1), 10000);
                try {
                    for ($i = 1; $i <= 80; $i++) {
                        $s->publish('fast.a', 'synthetic-N5-payload');
                    }
                    $s->awaitAcknowledged('80');
                    self::fail('Missing receipt for frame 80 must fail');
                } catch (FastBatchException $error) {
                    $result = $error->getResult();
                    self::assertSame('80', $result->getLastSentSequence());
                    self::assertSame('79', $result->getLastAcknowledgedSequence());
                    self::assertSame([['from' => '80', 'to' => '80']], $result->getUnknownRanges());
                    self::assertTrue($result->isOutcomeUnknown());
                    if ($kind === 'write') {
                        $root = $error;
                        while ($root->getPrevious() !== null) {
                            $root = $root->getPrevious();
                        } self::assertSame($cause, $root);
                    }
                }
                $h->afterFrame = null;
                $next          = $h->reconnect();
                $h->wait(fn (): bool => $h->buffer->getPendingMessages() === 0);
                self::assertCount(80, $h->frames());
                self::assertSame([], $h->storage->all());
                self::assertFalse($h->buffer->hasFailedFrame());
                self::assertSame(0, $h->buffer->getPendingBytes());
                $new = (new JetStreamPublisher($h->client))->openFastBatch('S', ['fast.a'], new FastBatchOptions(initialFlow: 1), 5000);
                self::assertTrue($new->commit('fast.a', 'new-session')->isComplete());
                self::assertCount(81, $h->frames());
                self::assertNotSame($result->getBatchId(), $new->getResult()->getBatchId());
                self::assertSame([], array_values(array_filter($h->frames(), static fn ($frame): bool => $frame['session'] === $next && $frame['batch'] === $result->getBatchId())));
                $this->trace('frame80-' . $kind, $h, ['sent' => '80', 'confirmed' => '79', 'unknown' => [['from' => '80', 'to' => '80']], 'post_reconnect_old_frames' => 0, 'next_session_success' => true]);
            } finally {
                $h->close();
            }
        });
    }
    public static function withdrawals(): iterable
    {
        yield ['cancel'];
        yield ['timeout'];
        yield ['disconnect'];
    }
    #[DataProvider('withdrawals')]
    public function testUnsentFrameIsWithdrawnAndLateCallbackCannotAffectAnotherSession(string $kind): void
    {
        $this->setTimeout(15);
        $this->runAsyncTest(function () use ($kind): void {
            $h = new FastBatchTcpHarness();
            try {
                $h->afterFrame = function ($frame) use ($h): void {
                    $h->ack($frame);
                    if ($frame['sequence'] === 1) {
                        $h->buffer->pause();
                    }
                };
                $timers        = $this->timers();
                $s             = (new JetStreamPublisher($h->client))->openFastBatch('S', ['fast.a'], new FastBatchOptions(initialFlow: 1), $kind === 'timeout' ? 500 : 5000);
                $s->publish('fast.a', 'first');
                $s->awaitAcknowledged('1');
                $late    = array_values($h->storage->all())[0];
                $pending = async(fn () => $s->publish('fast.a', 'never-written'));
                $h->wait(fn (): bool => $h->buffer->getPendingMessages() >= 1);
                if ($kind === 'cancel') {
                    $s->cancel();
                }
                if ($kind === 'disconnect') {
                    $h->server->closeSession(0);
                }
                try {
                    $pending->await();
                    self::fail('Unsent write cannot outlive its session');
                } catch (FastBatchException $error) {
                    self::assertSame('1', $error->getResult()->getLastSentSequence());
                    self::assertSame('1', $error->getResult()->getLastAcknowledgedSequence());
                    self::assertFalse($error->getResult()->isOutcomeUnknown());
                }
                $h->afterFrame = null;
                if ($kind === 'disconnect') {
                    $h->reconnect();
                } else {
                    $h->buffer->resume();
                    $h->barrier(0);
                }
                $h->wait(fn (): bool => $h->buffer->getPendingMessages() === 0);
                self::assertCount(1, $h->frames());
                self::assertSame([], $h->storage->all());
                self::assertSame(0, $h->buffer->getPendingBytes());
                self::assertFalse($h->buffer->hasFailedFrame());
                if ($kind === 'cancel') {
                    gc_collect_cycles();
                    self::assertSame($timers, $this->timers(), 'Closed session releases timers even while its callback is retained');
                }
                $new       = (new JetStreamPublisher($h->client))->openFastBatch('S', ['fast.a'], new FastBatchOptions(initialFlow: 1), 5000);
                $oldReply  = $h->frames()[0]['reply'];
                $oldResult = $s->getResult();
                $late(new \Dorpmaster\Nats\Protocol\MsgMessage($oldReply, '1', '{"type":"ack","seq":2,"msgs":1}'));
                self::assertSame($oldResult, $s->getResult());
                self::assertSame('0', $new->getProgress()->getLastAcknowledgedSequence());
                self::assertTrue($new->commit('fast.a', 'new')->isComplete());
                $this->trace('unsent-' . $kind, $h, ['old_sent' => '1', 'old_confirmed' => '1', 'unsent_frame2' => true, 'next_session_success' => true]);
            } finally {
                $h->close();
            }
        });
    }
    public function testLostFinalCommitIsSentOnceAndNextConnectionWorks(): void
    {
        $this->setTimeout(15);
        $this->runAsyncTest(function (): void {
            $h = new FastBatchTcpHarness();
            try {
                $h->afterFrame = function ($frame) use ($h): void {
                    if ($frame['operation'] !== 3) {
                        $h->ack($frame);
                    }
                };
                $s             = (new JetStreamPublisher($h->client))->openFastBatch('S', ['fast.a'], new FastBatchOptions(initialFlow: 1, pingTimeoutMs: 50), 5000);
                $s->publish('fast.a', 'first');
                $s->awaitAcknowledged('1');
                try {
                    $s->finish();
                    self::fail('No final PubAck');
                } catch (FastBatchException $error) {
                    self::assertTrue($error->getResult()->isOutcomeUnknown());
                    self::assertSame('1', $error->getResult()->getLastAcknowledgedSequence());
                }
                $h->server->closeSession(0);
                $h->afterFrame = null;
                $h->reconnect();
                self::assertCount(2, $h->frames());
                self::assertCount(1, array_filter($h->frames(), static fn ($f): bool => $f['operation'] === 3));
                $h->wait(fn (): bool => $h->buffer->getPendingMessages() === 0);
                self::assertSame([], $h->storage->all());
                $this->trace('lost-final', $h, ['commit_count' => 1, 'confirmed' => '1', 'post_reconnect_old_frames' => 0]);
            } finally {
                $h->close();
            }
        });
    }
    public static function queuedOperations(): iterable
    {
        foreach (['commit', 'finish', 'ping'] as $operation) {
            yield 'cancel ' . $operation => [$operation, false];
        }
        foreach (['publish', 'commit', 'finish', 'ping'] as $operation) {
            yield 'expired ' . $operation => [$operation, true];
        }
    }
    #[DataProvider('queuedOperations')]
    public function testQueuedControlAndDataCannotOutliveSession(string $operation, bool $expire): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($operation, $expire): void {
            $h = new FastBatchTcpHarness();
            try {
                $h->afterFrame = function ($frame) use ($h, $operation): void {
                    if ($operation === 'ping') {
                        $h->reply($frame, '{"type":"ack","seq":0,"msgs":1}');
                    } else {
                        $h->ack($frame);
                    }
                    $h->buffer->pause();
                };
                $s             = (new JetStreamPublisher($h->client))->openFastBatch('S', ['fast.a'], new FastBatchOptions(initialFlow: 1, ackTimeoutMs: $operation === 'ping' ? 10 : 1000), $expire ? 1500 : 5000);
                $s->publish('fast.a', 'before');
                if ($operation !== 'ping') {
                    $s->awaitAcknowledged('1');
                }
                $h->buffer->pause();
                $pending = async(fn () => match ($operation) {
                    'finish' => $s->finish(),
                    'ping' => $s->awaitAcknowledged('1'),
                    default => $s->$operation('fast.a', 'never-written'),
                });
                $h->wait(fn (): bool => $h->buffer->getPendingMessages() >= 1);
                if ($expire) {
                    usleep(1600000); // Queue already contains the frame while timers cannot run.
                    $h->buffer->resume();
                } else {
                    $s->cancel();
                }
                $h->wait(fn (): bool => $pending->isComplete());
                try {
                    $pending->await();
                    self::fail('A cancelled/expired queued operation cannot succeed');
                } catch (FastBatchException $error) {
                    self::assertSame($expire ? 408 : 0, $error->getApiCode());
                    self::assertSame('1', $error->getResult()->getLastSentSequence());
                    self::assertSame($operation === 'ping', $error->getResult()->isOutcomeUnknown());
                }
                $h->buffer->resume();
                $h->barrier(0);
                $h->wait(fn (): bool => $h->buffer->getPendingMessages() === 0);
                self::assertCount(1, $h->frames());
                self::assertSame(0, $h->buffer->getPendingBytes());
                self::assertFalse($h->buffer->hasFailedFrame());
                self::assertSame([], $h->storage->all());
                $this->trace('queued-' . ($expire ? 'expired-' : 'cancel-') . $operation, $h, ['frames_before' => 1, 'frames_after_resume' => count($h->frames()), 'sent' => $s->getResult()->getLastSentSequence(), 'unknown' => $s->getResult()->isOutcomeUnknown(), 'next_request_success' => true]);
            } finally {
                $h->close();
            }
        });
    }
    public static function settlementCases(): iterable
    {
        foreach (['finish', 'awaitAcknowledged'] as $operation) {
            foreach (['before', 'after', 'lost'] as $order) {
                foreach ([false, true] as $confirmed) {
                    yield $operation . '-' . $order . '-ack' . (int) $confirmed => [$operation, $order, $confirmed, false];
                }
            }
            yield $operation . '-gap-after' => [$operation, 'after', false, true];
        }
    }
    #[DataProvider('settlementCases')]
    public function testServerRejectionWithdrawsControlButKeepsInboxForFinal(string $operation, string $order, bool $confirmed, bool $gap): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($operation, $order, $confirmed, $gap): void {
            $h = new FastBatchTcpHarness();
            try {
                $last          = $gap ? 3 : 2;
                $h->afterFrame = function ($frame) use ($h, $confirmed, $last): void {
                    if ($frame['sequence'] === 1) {
                        $h->reply($frame, '{"type":"ack","seq":0,"msgs":1}');
                        if ($confirmed) {
                            $h->reply($frame, '{"type":"ack","seq":1,"msgs":1}');
                        }
                    }
                    if ($frame['sequence'] === $last) {
                        $h->buffer->pause();
                    }
                };
                $started       = hrtime(true);
                $session       = (new JetStreamPublisher($h->client))->openFastBatch('S', ['fast.a'], new FastBatchOptions(initialFlow: 1, maxInflightWindows: 3, ackTimeoutMs: 200), 2000);
                $session->publish('fast.a', 'first');
                if ($confirmed) {
                    $session->awaitAcknowledged('1');
                }
                $session->publish('fast.a', 'rejected', ['Nats-Expected-Last-Sequence' => '99']);
                if ($gap) {
                    $session->publish('fast.a', 'after-gap');
                }
                $reply   = $h->frames()[$last - 1];
                $sid     = explode(' ', array_values(array_filter($h->server->rawCommands(0), static fn ($command): bool => str_starts_with($command, 'SUB _INBOX.FAST.')))[0])[2];
                $pending = async(fn () => $operation === 'finish' ? $session->finish() : $session->awaitAcknowledged((string) $last));
                $h->wait(fn (): bool => $h->buffer->getPendingMessages() >= 1);
                $notice = $gap ? '{"type":"gap","last_seq":2,"seq":3}' : '{"type":"err","seq":2,"error":{"code":400,"err_code":10071,"description":"wrong last sequence: 1"}}';
                $h->reply($reply, $notice);
                $h->wait(fn (): bool => (new \ReflectionProperty($session, 'stopReason'))->getValue($session) !== null);
                $final               = '{"stream":"S","batch":"' . $reply['batch'] . '","count":1,"seq":1}';
                $commandsBeforeFinal = $h->server->rawCommands(0);
                if ($order === 'before') {
                    $h->reply($reply, $final);
                    $h->wait(fn (): bool => $session->getResult() !== null);
                }
                $h->buffer->resume();
                $h->barrier(0);
                if ($order !== 'before') {
                    $commandsBeforeFinal = $h->server->rawCommands(0);
                    self::assertFalse($pending->isComplete(), 'The final acknowledgement must still be awaited');
                    self::assertNotContains('UNSUB ' . $sid, $h->server->rawCommands(0));
                    self::assertCount(1, $h->storage->all());
                }
                if ($order === 'after') {
                    $h->reply($reply, $final);
                }
                try {
                    $pending->await();
                    self::fail('Rejected batch cannot succeed');
                } catch (FastBatchException $error) {
                    self::assertSame($gap ? 0 : 400, $error->getApiCode());
                    self::assertSame($gap ? null : 10071, $error->getErrorCode());
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
                $h->barrier(0);
                self::assertContains('UNSUB ' . $sid, $h->server->rawCommands(0));
                self::assertSame($gap ? [0, 1, 1] : [0, 1], array_column($h->frames(), 'operation'));
                self::assertSame([], $h->storage->all());
                self::assertSame(0, $h->buffer->getPendingBytes());
                $this->trace('settlement-' . $operation . '-' . $order . '-ack' . (int) $confirmed . ($gap ? '-gap' : ''), $h, ['final_received' => $result->getFinalAck() !== null, 'confirmed' => $result->getLastAcknowledgedSequence(), 'unknown_ranges' => $result->getUnknownRanges(), 'problem' => $result->getProblemSequence(), 'inbox_open_until_settlement' => true, 'commands_before_final_or_timeout' => array_values(array_filter($commandsBeforeFinal, static fn ($command): bool => preg_match('/\A(?:SUB|UNSUB|HPUB) /', $command) === 1)), 'commands' => array_values(array_filter($h->server->rawCommands(0), static fn ($command): bool => preg_match('/\A(?:SUB|UNSUB|HPUB) /', $command) === 1)), 'elapsed_ms' => (hrtime(true) - $started) / 1000000]);
            } finally {
                $h->buffer->resume();
                $h->close();
            }
        });
    }
    private function timers(): array
    {
        return array_values(array_filter(\Revolt\EventLoop::getIdentifiers(), static fn ($id): bool => \Revolt\EventLoop::getType($id) === \Revolt\EventLoop\CallbackType::Delay));
    }
    private function trace(string $name, FastBatchTcpHarness $h, array $observations): void
    {
        $directory = getenv('NATS_FAST_TRACE_DIR');
        if ($directory) {
            $frames = array_map(static fn ($f) => ['command' => $f['command'], 'body_base64' => base64_encode($f['payload']), 'session' => $f['session'], 'batch' => $f['batch'], 'sequence' => $f['sequence'], 'operation' => $f['operation']], $h->frames());
            file_put_contents($directory . '/' . $name . '.json', json_encode(['frames' => $frames, 'observations' => $observations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
