<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\WriteBuffer\RequestLifetime;
use Dorpmaster\Nats\Client\WriteBufferService;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\RequestWriteException;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\OutboundFrameBuilder;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class SingleAttemptWriteBufferTest extends TestCase
{
    use AsyncTestTools;

    public function testUncertainWriteIsReleasedAndNotSavedInFailedFrame(): void
    {
        $this->runAsyncTest(function (): void {
            $cause      = new ConnectionException('synthetic write failure');
            $connection = self::createMock(ConnectionInterface::class);
            $connection->expects(self::once())->method('send')->willThrowException($cause);
            $errors   = [];
            $lifetime = new RequestLifetime(static function (\Throwable $error) use (&$errors): void {
                $errors[] = $error;
            }, new NullCancellation());
            $buffer   = new WriteBufferService(2, 1000);
            try {
                $buffer->start($connection);
                $buffer->enqueue((new OutboundFrameBuilder())->build(new PubMessage('update', 'body'), $lifetime));
                $this->forceTick();
                self::assertCount(1, $errors);
                self::assertInstanceOf(RequestWriteException::class, $errors[0]);
                self::assertSame($cause, $errors[0]->getPrevious());
                self::assertFalse($buffer->hasFailedFrame());
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame(0, $buffer->getPendingMessages());
                $buffer->start($connection);
                $this->forceTick();
            } finally {
                $buffer->stop();
            }
        });
    }

    public function testCancelledFrameHeldBeforeFirstAttemptIsRemovedFromFailedFrame(): void
    {
        $this->runAsyncTest(function (): void {
            $closed     = true;
            $connection = self::createMock(ConnectionInterface::class);
            $connection->method('isClosed')->willReturnCallback(static function () use (&$closed): bool {
                return $closed;
            });
            $connection->expects(self::never())->method('send');
            $lifetime = new RequestLifetime(static fn (): null => null, new NullCancellation());
            $buffer   = new WriteBufferService(1, 1000);
            try {
                $buffer->start($connection);
                $buffer->enqueue((new OutboundFrameBuilder())->build(new PubMessage('update', 'body'), $lifetime));
                $this->forceTick();
                self::assertTrue($buffer->hasFailedFrame());
                self::assertSame(1, $buffer->getPendingMessages());
                $lifetime->finish();
                self::assertFalse($buffer->hasFailedFrame());
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame(0, $buffer->getPendingMessages());
                $closed = false;
                $buffer->start($connection);
                $this->forceTick();
            } finally {
                $buffer->stop();
            }
        });
    }

    public function testCancelledPausedFramesReleasePhysicalCapacityAndAccountOnlyOnce(): void
    {
        $this->setTimeout(2);
        $this->runAsyncTest(function (): void {
            $connection = self::createMock(ConnectionInterface::class);
            $connection->expects(self::once())->method('send')->with(self::callback(static fn ($frame): bool => (string) $frame === "PUB next 2\r\nok\r\n"));
            $buffer = new WriteBufferService(1, 1000);
            $buffer->pause();
            $buffer->start($connection);
            try {
                for ($index = 0; $index < 20; $index++) {
                    $source   = new DeferredCancellation();
                    $lifetime = new RequestLifetime(static fn (): null => null, $source->getCancellation());
                    $buffer->enqueue((new OutboundFrameBuilder())->build(new PubMessage('expired', 'body'), $lifetime));
                    $source->cancel();
                    $lifetime->finish();
                    $lifetime->finish();
                    self::assertSame(0, $buffer->getPendingMessages());
                    self::assertSame(0, $buffer->getPendingBytes());
                }
                $frame = (new OutboundFrameBuilder())->build(new PubMessage('next', 'ok'));
                self::assertTrue($buffer->enqueue($frame));
                self::assertSame($frame->bytes, $buffer->getPendingBytes());
                $buffer->resume();
                $this->forceTick();
                self::assertSame(0, $buffer->getPendingMessages());
                self::assertSame(0, $buffer->getPendingBytes());
            } finally {
                $buffer->stop();
            }
        });
    }

    public function testAlreadyRequestedCancellationPreventsAttemptBeforeCallbackRuns(): void
    {
        $this->runAsyncTest(function (): void {
            $source     = new DeferredCancellation();
            $lifetime   = new RequestLifetime(static fn (): null => null, $source->getCancellation());
            $connection = self::createMock(ConnectionInterface::class);
            $connection->expects(self::never())->method('send');
            $buffer = new WriteBufferService(1, 1000);
            try {
                $buffer->start($connection);
                $buffer->enqueue((new OutboundFrameBuilder())->build(new PubMessage('update', 'body'), $lifetime));
                $source->cancel();
                $this->forceTick();
                self::assertSame(0, $buffer->getPendingMessages());
                self::assertFalse($buffer->hasFailedFrame());
            } finally {
                $lifetime->finish();
                $buffer->stop();
            }
        });
    }

    public function testLegacyBufferIsRejectedBeforeInboxOrEnqueue(): void
    {
        $buffer = self::createMock(WriteBufferInterface::class);
        $buffer->expects(self::never())->method('enqueue');
        $storage = self::createMock(SubscriptionStorageInterface::class);
        $storage->expects(self::never())->method('add');
        $client = new Client(new ClientConfiguration(), new NullCancellation(), self::createStub(ConnectionInterface::class), new EventDispatcher(), self::createStub(MessageDispatcherInterface::class), $storage, writeBuffer: $buffer);
        self::expectException(ConnectionException::class);
        self::expectExceptionMessage('SingleAttemptWriteBufferInterface');
        $client->requestOnce(new PubMessage('update', 'body'));
    }
}
