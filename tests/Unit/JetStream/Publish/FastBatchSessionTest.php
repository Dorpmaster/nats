<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Amp\DeferredCancellation;
use Dorpmaster\Nats\Domain\JetStream\Exception\FastBatchException;
use Dorpmaster\Nats\Domain\JetStream\Publish\FastBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Tests\Support\FakeFastBatchClient;
use Dorpmaster\Nats\Tests\Support\FakeFastBatchChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class FastBatchSessionTest extends TestCase
{
    public static function expiredOperations(): iterable
    {
        yield ['publish'];
        yield ['commit'];
        yield ['finish'];
    }
    #[DataProvider('expiredOperations')]
    public function testExpiredSessionCannotSendAfterBlockedEventLoop(string $operation): void
    {
        $client = new FakeFastBatchClient();
        $s      = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 1), 60);
        if ($operation !== 'commit') {
            $s->publish('orders.a', 'before');
            $s->awaitAcknowledged('1');
        }
        $before = count($client->channels[0]->records);
        usleep(90000); // Deliberately starve timers beyond the monotonic deadline.
        try {
            $operation === 'finish' ? $s->finish() : $s->$operation('orders.a', 'after');
            self::fail('An expired session must reject before send');
        } catch (FastBatchException $error) {
            self::assertSame(408, $error->getApiCode());
        }
        self::assertCount($before, $client->channels[0]->records);
        self::assertFalse($s->getResult()->isOutcomeUnknown());
    }
    #[DataProvider('expiredOperations')]
    public function testDeadlineIsCheckedImmediatelyBeforeWrite(string $operation): void
    {
        $client = new FakeFastBatchClient();
        $s      = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 1), 60);
        if ($operation !== 'commit') {
            $s->publish('orders.a', 'before');
            $s->awaitAcknowledged('1');
        }
        $before                = count($client->channels[0]->records);
        $client->beforeAttempt = static fn () => usleep(90000);
        try {
            $operation === 'finish' ? $s->finish() : $s->$operation('orders.a', 'after');
            self::fail('The write guard must check the actual deadline again');
        } catch (FastBatchException $error) {
            self::assertSame(408, $error->getApiCode());
        }
        self::assertCount($before, $client->channels[0]->records);
        self::assertFalse($s->getResult()->isOutcomeUnknown());
    }
    public function testSessionConstructorCannotBypassAdmission(): void
    {
        $client        = new FakeFastBatchClient();
        $client->level = 0;
        try {
            new \Dorpmaster\Nats\Domain\JetStream\Publish\FastBatchSession($client, 'S', ['orders.a'], new FastBatchOptions(), hrtime(true) + 1000000000, new \Amp\NullCancellation(), 'synthetic', 1);
            self::fail('Direct construction must not open a channel');
        } catch (\Error $error) {
            self::assertStringContainsString('private', $error->getMessage());
        }
        self::assertSame([], $client->channels);
    }
    #[DataProvider('modes')]
    public function testDeduplicatedBatchCanFinishAtStreamSequenceZero(string $mode): void
    {
        $client                 = new FakeFastBatchClient();
        $client->streamSequence = '0';
        $s                      = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 1));
        if ($mode !== 'single') {
            $s->publish('orders.a', 'same', ['Nats-Msg-Id' => 'duplicate']);
        }
        $result = $mode === 'eob' ? $s->finish() : $s->commit('orders.a', 'same', ['Nats-Msg-Id' => 'duplicate']);
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isOutcomeUnknown());
        self::assertSame('0', $result->getFinalAck()->getSeq());
        self::assertSame($mode === 'message' ? '2' : '1', $result->getFinalAck()->getCount());
    }
    public static function optionErrors(): iterable
    {
        foreach ([['initialFlow' => 0], ['initialFlow' => 65536], ['maxInflightWindows' => 0], ['maxInflightWindows' => 4], ['maxBufferedBytes' => 1], ['maxMessageBytes' => 255], ['maxMessageBytes' => 2097152], ['ackTimeoutMs' => 0], ['pingTimeoutMs' => -1]] as $args) {
            yield [$args];
        }
    }
    #[DataProvider('optionErrors')]
    public function testOptionsRejectUnboundedOrInvalidCredit(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FastBatchOptions(...$args);
    }
    public static function modes(): iterable
    {
        yield ['single'];
        yield ['message'];
        yield ['eob'];
    }
    #[DataProvider('modes')]
    public function testControlProtocolAndExactUInt64Final(string $mode): void
    {
        $client          = new FakeFastBatchClient();
        $client->autoAck = false;
        $s               = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a', 'orders.b'], new FastBatchOptions(initialFlow: 4));
        $bag             = new HeaderBag(['X' => ['one', 'two']]);
        if ($mode !== 'single') {
            $progress = $s->publish('orders.a', "A\r\n\0", $bag);
            self::assertSame('1', $progress->getLastSentSequence());
            self::assertSame('0', $progress->getLastAcknowledgedSequence(), 'ACK 0 cannot confirm frame 1');
            $bag->set('X', 'changed');
        }
        $result = $mode === 'eob' ? $s->finish() : $s->commit('orders.b', 'last');
        self::assertTrue($result->isComplete());
        self::assertSame('18446744073709551615', $result->getFinalAck()->getSeq());
        self::assertSame($mode === 'message' ? '2' : '1', $result->getLastAcknowledgedSequence());
        self::assertSame([], $result->getUnknownRanges());
        self::assertSame(0, $result->getProgress()->getPendingBytes());
        $records = $client->channels[0]->records;
        self::assertSame($mode === 'single' ? [2] : ($mode === 'eob' ? [0, 3] : [0, 2]), array_column($records, 'operation'));
        foreach ($records as $record) {
            self::assertNull($record['message']->getHeaders()->get('Nats-Batch-Id'));
            self::assertStringContainsString('.fail.', $record['message']->getReplyTo());
            self::assertStringEndsWith('.$FI', $record['message']->getReplyTo());
        }
        if ($mode !== 'single') {
            self::assertStringContainsString("X: one\r\nX: two\r\n", $records[0]['wire']);
            self::assertSame("A\r\n\0", $records[0]['message']->getPayload());
        }
        self::assertSame(1, $client->channels[0]->closes);
        self::assertSame($result, $s->cancel());
    }
    public function testDynamicWindowCumulativeAndLateAcks(): void
    {
        $client          = new FakeFastBatchClient();
        $client->autoAck = false;
        $client->window  = 2;
        $s               = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 4, ackTimeoutMs: 1000));
        for ($i = 1; $i <= 4; $i++) {
            $s->publish('orders.a', (string) $i);
        }
        $next = async(fn () => $s->publish('orders.a', '5'));
        delay(.01);
        self::assertFalse($next->isComplete());
        self::assertCount(4, $client->channels[0]->records);
        $client->channels[0]->emit('{"type":"ack","seq":2,"msgs":1}');
        delay(.01);
        self::assertFalse($next->isComplete());
        self::assertSame(1, $s->getProgress()->getWindow());
        $client->channels[0]->emit('{"type":"ack","seq":1,"msgs":4}');
        delay(.01);
        self::assertSame('2', $s->getProgress()->getLastAcknowledgedSequence());
        self::assertSame(1, $s->getProgress()->getWindow());
        $client->channels[0]->emit('{"type":"ack","seq":4,"msgs":4}');
        $next->await();
        self::assertSame('4', $s->getProgress()->getLastAcknowledgedSequence());
        self::assertSame(4, $s->getProgress()->getWindow());
        self::assertSame([['from' => '5', 'to' => '5']], $s->cancel()->getUnknownRanges());
    }
    public function testThreeWindowsBoundTheProducer(): void
    {
        $client          = new FakeFastBatchClient();
        $client->autoAck = false;
        $client->window  = 2;
        $s               = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 2, maxInflightWindows: 3, ackTimeoutMs: 1000));
        for ($i = 1; $i <= 6; $i++) {
            $s->publish('orders.a', (string) $i);
        }
        $next = async(fn () => $s->publish('orders.a', '7'));
        delay(.01);
        self::assertFalse($next->isComplete());
        self::assertCount(6, $client->channels[0]->records);
        self::assertSame(6, $s->getProgress()->getPendingMessages());
        $client->channels[0]->emit('{"type":"ack","seq":2,"msgs":2}');
        $next->await();
        self::assertSame('7', $s->getProgress()->getLastSentSequence());
        self::assertSame(5, $s->getProgress()->getPendingMessages());
        $s->cancel();
    }
    public function testPingRecoversLostAckWithoutAdvancingSequence(): void
    {
        $client         = new FakeFastBatchClient();
        $dropped        = false;
        $client->filter = static function ($message) use (&$dropped): bool {
            if (!$dropped && str_contains($message->getPayload(), '"seq":1')) {
                $dropped = true;
                return false;
            }
            return true;
        };
        $s              = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 1, ackTimeoutMs: 10, pingTimeoutMs: 100), 1000);
        $s->publish('orders.a', 'first');
        $s->awaitAcknowledged('1');
        self::assertSame('1', $s->getProgress()->getLastAcknowledgedSequence());
        self::assertSame([0, 4], array_column($client->channels[0]->records, 'operation'));
        self::assertSame([1, 1], array_column($client->channels[0]->records, 'sequence'));
        self::assertTrue($s->finish()->isComplete());
    }
    public function testByteCreditFreezesHeadersWhileProducerIsBlocked(): void
    {
        $client          = new FakeFastBatchClient();
        $client->autoAck = false;
        $client->window  = 1;
        $s               = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 2, maxInflightWindows: 3, maxMessageBytes: 512, maxBufferedBytes: 1024, ackTimeoutMs: 1000));
        $payload         = str_repeat('x', 260);
        $s->publish('orders.a', $payload);
        $s->publish('orders.a', $payload);
        $bag  = new HeaderBag(['X' => 'before']);
        $next = async(fn () => $s->publish('orders.a', $payload, $bag));
        delay(.01);
        self::assertFalse($next->isComplete());
        self::assertCount(2, $client->channels[0]->records);
        self::assertLessThanOrEqual(1024, $s->getProgress()->getPendingBytes());
        $bag->set('X', 'after');
        $client->channels[0]->emit('{"type":"ack","seq":2,"msgs":1}');
        $next->await();
        self::assertSame('before', $client->channels[0]->records[2]['message']->getHeaders()->get('X'));
        $s->cancel();
    }
    public static function partialKinds(): iterable
    {
        yield ['gap'];
        yield ['err'];
        yield ['lost notice'];
    }
    #[DataProvider('partialKinds')]
    public function testPartialFinalCannotBecomeFullSuccess(string $kind): void
    {
        $client          = new FakeFastBatchClient();
        $client->onFrame = static function ($message, FakeFastBatchChannel $channel) use ($kind): void {
            $tokens = explode('.', $message->getReplyTo());
            $seq    = (int) $tokens[count($tokens) - 3];
            $id     = $tokens[count($tokens) - 6];
            if ($seq === 1) {
                $channel->emit('{"type":"ack","seq":0,"msgs":2}');
                $channel->emit('{"type":"ack","seq":1,"msgs":2}');
            }
            if ($seq === 3) {
                if ($kind === 'gap') {
                    $channel->emit('{"type":"gap","last_seq":2,"seq":3}');
                }
                if ($kind === 'err') {
                    $channel->emit('{"type":"err","seq":2,"error":{"code":400,"err_code":10071,"description":"wrong last sequence"}}');
                }
                $channel->emit('{"stream":"S","batch":"' . $id . '","count":1,"seq":9}');
            }
        };
        $s               = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 2));
        $s->publish('orders.a', '1');
        $s->publish('orders.a', '2');
        try {
            $s->commit('orders.a', '3');
            self::fail('A partial PubAck is not complete success');
        } catch (FastBatchException $error) {
            $result = $error->getResult();
            self::assertFalse($result->isComplete());
            self::assertFalse($result->isOutcomeUnknown());
            self::assertSame('3', $result->getLastSentSequence());
            self::assertSame('1', $result->getLastAcknowledgedSequence());
            self::assertSame('2', $result->getProblemSequence());
            self::assertSame('1', $result->getFinalAck()->getCount());
            if ($kind === 'err') {
                self::assertSame(400, $error->getApiCode());
                self::assertSame(10071, $error->getErrorCode());
            }
        }
    }
    public function testFirstMessageRejectionUsesReplySequenceNotStreamSequence(): void
    {
        $client          = new FakeFastBatchClient();
        $client->onFrame = static fn ($message, $channel) => $channel->emit('{"stream":"S","seq":0,"error":{"code":400,"err_code":10071,"description":"wrong last sequence"}}');
        $s               = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a']);
        try {
            $s->commit('orders.a', 'rejected');
            self::fail('First record failed');
        } catch (FastBatchException $error) {
            self::assertSame('1', $error->getResult()->getProblemSequence());
            self::assertFalse($error->getResult()->isOutcomeUnknown());
            self::assertSame('0', $error->getResult()->getLastAcknowledgedSequence());
            self::assertSame(10071, $error->getErrorCode());
        }
    }
    public function testTimeoutAndLateReplyReleaseTheSessionOnce(): void
    {
        $client          = new FakeFastBatchClient();
        $client->onFrame = static function (): void {
        };
        $s               = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(initialFlow: 1, ackTimeoutMs: 10, pingTimeoutMs: 20), 200);
        try {
            $s->publish('orders.a', 'first');
            self::fail('Missing ACK must time out');
        } catch (FastBatchException $error) {
            self::assertTrue($error->getResult()->isOutcomeUnknown());
            self::assertSame(408, $error->getApiCode());
        }
        $result = $s->getResult();
        $client->channels[0]->emit('{"type":"ack","seq":1,"msgs":1}', force: true);
        delay(.01);
        self::assertSame($result, $s->getResult());
        self::assertSame('0', $result->getLastAcknowledgedSequence());
        self::assertSame(1, $client->channels[0]->closes);
    }
    public function testTwoSessionsAndExternalCancellationAreIndependent(): void
    {
        $client = new FakeFastBatchClient();
        $source = new DeferredCancellation();
        $a      = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a'], new FastBatchOptions(cancellation: $source->getCancellation()));
        $b      = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.b']);
        $a->publish('orders.a', 'A');
        $source->cancel();
        delay(.01);
        self::assertTrue($a->getResult()->isCancelled());
        self::assertNull($b->getResult());
        $result = $b->commit('orders.b', 'B');
        self::assertTrue($result->isComplete());
        self::assertNotSame($a->getResult()->getBatchId(), $result->getBatchId());
        self::assertNotSame($client->channels[0]->inbox, $client->channels[1]->inbox);
    }
}
