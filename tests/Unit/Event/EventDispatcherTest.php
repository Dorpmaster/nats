<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Event;

use Amp\DeferredFuture;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Tests\AsyncTestCase;
use InvalidArgumentException;
use Revolt\EventLoop;

final class EventDispatcherTest extends AsyncTestCase
{
    public function testDispatchException(): void
    {
        $dispatcher = new EventDispatcher();

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Event name cannot be empty string');

        $dispatcher->dispatch('');
    }

    public function testDispatchNoPayload(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $dispatcher = new EventDispatcher();

            $testEventName = $testPayload = null;

            $callback = static function (
                string $eventName,
                mixed  $payload,
            ) use (&$testEventName, &$testPayload): void {
                $testEventName = $eventName;
                $testPayload = $payload;
            };

            $dispatcher->subscribe('NoPayload', $callback);

            $dispatcher->dispatch('NoPayload');

            // Force an extra tick of the event loop to ensure any callbacks are
            // processed to the event loop handler before start assertionsI.
            $deferred = new DeferredFuture();
            EventLoop::defer(static fn () => $deferred->complete());
            $deferred->getFuture()->await();

            self::assertSame('NoPayload', $testEventName);
            self::assertNull($testPayload);
        });
    }

    public function testDispatchPayload(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $dispatcher = new EventDispatcher();

            $testEventName = $testPayload = null;

            $callback = static function (
                string $eventName,
                mixed  $payload,
            ) use (&$testEventName, &$testPayload): void {
                $testEventName = $eventName;
                $testPayload = $payload;
            };

            $dispatcher->subscribe('NoPayload', $callback);

            $dispatcher->dispatch('NoPayload', 'test');

            // Force an extra tick of the event loop to ensure any callbacks are
            // processed to the event loop handler before start assertions.
            $deferred = new DeferredFuture();
            EventLoop::defer(static fn () => $deferred->complete());
            $deferred->getFuture()->await();

            self::assertSame('NoPayload', $testEventName);
            self::assertSame('test', $testPayload);
        });
    }
}
