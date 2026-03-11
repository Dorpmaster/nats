<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Client\InboundDispatchScheduler;
use Dorpmaster\Nats\Domain\Client\InboundDispatchOverflowException;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class InboundDispatchSchedulerTest extends TestCase
{
    use AsyncTestTools;

    public function testDoesNotScheduleMoreThanConfiguredConcurrentDispatches(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst  = new DeferredFuture();
            $firstStarted  = new DeferredFuture();
            $secondStarted = false;

            $scheduler = new InboundDispatchScheduler(1, 4, $this->logger);
            $scheduler->dispatch($this->message('first'), static function () use ($releaseFirst, $firstStarted): void {
                $firstStarted->complete();
                $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
            });
            $scheduler->dispatch($this->message('second'), static function () use (&$secondStarted): void {
                $secondStarted = true;
            });

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));
            $this->forceTick();

            self::assertSame(1, $scheduler->getActiveCount());
            self::assertSame(1, $scheduler->getPendingCount());
            self::assertFalse($secondStarted);

            $releaseFirst->complete();
        });
    }

    public function testPendingDispatchStartsAfterActiveTaskCompletes(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst  = new DeferredFuture();
            $firstStarted  = new DeferredFuture();
            $secondStarted = new DeferredFuture();

            $scheduler = new InboundDispatchScheduler(1, 4, $this->logger);
            $scheduler->dispatch($this->message('first'), static function () use ($releaseFirst, $firstStarted): void {
                $firstStarted->complete();
                $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
            });
            $scheduler->dispatch($this->message('second'), static function () use ($secondStarted): void {
                $secondStarted->complete();
            });

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));
            $releaseFirst->complete();
            $secondStarted->getFuture()->await(new TimeoutCancellation(1));

            self::assertSame(0, $scheduler->getActiveCount());
            self::assertSame(0, $scheduler->getPendingCount());
        });
    }

    public function testDrainWaitsForActiveAndPendingDispatches(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst   = new DeferredFuture();
            $releaseSecond  = new DeferredFuture();
            $firstStarted   = new DeferredFuture();
            $secondStarted  = new DeferredFuture();
            $drainCompleted = false;

            $scheduler = new InboundDispatchScheduler(1, 4, $this->logger);
            $scheduler->dispatch($this->message('first'), static function () use ($releaseFirst, $firstStarted): void {
                $firstStarted->complete();
                $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
            });
            $scheduler->dispatch($this->message('second'), static function () use ($releaseSecond, $secondStarted): void {
                $secondStarted->complete();
                $releaseSecond->getFuture()->await(new TimeoutCancellation(1));
            });

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));

            $drainFuture = async(function () use ($scheduler, &$drainCompleted): void {
                $scheduler->drain(1_000);
                $drainCompleted = true;
            });

            $this->forceTick();
            self::assertFalse($drainCompleted);

            $releaseFirst->complete();
            $secondStarted->getFuture()->await(new TimeoutCancellation(1));
            self::assertFalse($drainCompleted);

            $releaseSecond->complete();
            $drainFuture->await(new TimeoutCancellation(1));

            self::assertTrue($drainCompleted);
            self::assertSame(0, $scheduler->getActiveCount());
            self::assertSame(0, $scheduler->getPendingCount());
        });
    }

    public function testPendingQueueOverflowThrowsException(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst = new DeferredFuture();
            $firstStarted = new DeferredFuture();

            $scheduler = new InboundDispatchScheduler(1, 1, $this->logger);
            $scheduler->dispatch($this->message('first'), static function () use ($releaseFirst, $firstStarted): void {
                $firstStarted->complete();
                $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
            });
            $scheduler->dispatch($this->message('second'), static function (): void {
            });

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));

            try {
                $scheduler->dispatch($this->message('third'), static function (): void {
                });
                self::fail('Expected InboundDispatchOverflowException');
            } catch (InboundDispatchOverflowException $exception) {
                self::assertStringContainsString('Inbound dispatch queue overflow', $exception->getMessage());
                self::assertSame(1, $scheduler->getActiveCount());
                self::assertSame(1, $scheduler->getPendingCount());
            } finally {
                $releaseFirst->complete();
            }
        });
    }

    public function testCallbackExceptionDoesNotCorruptSchedulerAccounting(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $secondStarted = new DeferredFuture();

            $scheduler = new InboundDispatchScheduler(1, 4, $this->logger);
            $scheduler->dispatch($this->message('first'), static function (): never {
                throw new \RuntimeException('boom');
            });
            $scheduler->dispatch($this->message('second'), static function () use ($secondStarted): void {
                $secondStarted->complete();
            });

            $secondStarted->getFuture()->await(new TimeoutCancellation(1));

            self::assertSame(0, $scheduler->getActiveCount());
            self::assertSame(0, $scheduler->getPendingCount());
        });
    }

    private function message(string $payload): MsgMessage
    {
        return new MsgMessage('subject', 'sid', $payload);
    }
}
