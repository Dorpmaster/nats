<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\WriteBufferPolicy;
use Dorpmaster\Nats\Client\WriteBufferService;
use Dorpmaster\Nats\Domain\Client\WriteBufferOverflowException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Protocol\OutboundFrameBuilder;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class WriteBufferServiceTest extends TestCase
{
    use AsyncTestTools;

    public function testEnqueueAndWriteDecrementsPendingCounters(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $sent       = 0;
            $connection = $this->createConnection(static function () use (&$sent): void {
                $sent++;
            });

            $service = new WriteBufferService(10, 10_000, WriteBufferPolicy::ERROR);
            $service->start($connection);
            $frame = (new OutboundFrameBuilder())->build(new PubMessage('s', 'p'));

            // Act
            $service->enqueue($frame);
            $this->forceTick();

            // Assert
            self::assertSame(1, $sent);
            self::assertSame(0, $service->getPendingMessages());
            self::assertSame(0, $service->getPendingBytes());
        });
    }

    public function testOverflowMessagesThrowsForErrorPolicy(): void
    {
        // Arrange
        $service = new WriteBufferService(1, 10_000, WriteBufferPolicy::ERROR);
        $frame   = (new OutboundFrameBuilder())->build(new PubMessage('s', 'payload'));

        // Act
        $service->enqueue($frame);

        // Assert
        self::expectException(WriteBufferOverflowException::class);

        // Act
        $service->enqueue($frame);
    }

    public function testOverflowBytesThrowsForErrorPolicy(): void
    {
        // Arrange
        $service = new WriteBufferService(10, 20, WriteBufferPolicy::ERROR);
        $frame   = (new OutboundFrameBuilder())->build(new PubMessage('subject', 'payload')); // >20 bytes as frame

        // Assert
        self::expectException(WriteBufferOverflowException::class);

        // Act
        $service->enqueue($frame);
    }

    public function testDropNewDoesNotIncreasePendingCounters(): void
    {
        // Arrange
        $service = new WriteBufferService(1, 10_000, WriteBufferPolicy::DROP_NEW);
        $frame   = (new OutboundFrameBuilder())->build(new PubMessage('s', 'payload'));

        // Act
        $service->enqueue($frame);
        $isQueued = $service->enqueue($frame);

        // Assert
        self::assertFalse($isQueued);
        self::assertSame(1, $service->getPendingMessages());
    }

    public function testFailedItemWrittenAfterReconnectWithoutDoubleCountAndSentFirst(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $sent             = [];
            $firstConnection  = $this->createConnection(static function (): void {
                throw new \RuntimeException('write failed');
            });
            $secondConnection = $this->createConnection(static function (string $wire) use (&$sent): void {
                $sent[] = $wire;
            });

            $builder = new OutboundFrameBuilder();
            $frameA  = $builder->build(new PubMessage('a', 'first'));
            $frameB  = $builder->build(new PubMessage('b', 'second'));

            $service = new WriteBufferService(10, 10_000, WriteBufferPolicy::ERROR);
            $service->start($firstConnection);
            $service->enqueue($frameA);
            $service->enqueue($frameB);

            // Act
            $this->forceTick(); // fail frameA on first connection
            $service->start($secondConnection);
            $this->forceTick();

            // Assert
            self::assertSame([(string) $frameA->message, (string) $frameB->message], $sent);
            self::assertSame(0, $service->getPendingMessages());
            self::assertSame(0, $service->getPendingBytes());
        });
    }

    public function testDrainDuringReconnectFinishesDeterministically(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $firstConnection  = $this->createConnection(static function (): void {
                throw new \RuntimeException('write failed');
            });
            $secondConnection = $this->createConnection(static function (): void {
            });

            $service = new WriteBufferService(10, 10_000, WriteBufferPolicy::ERROR);
            $service->start($firstConnection);
            $service->enqueue((new OutboundFrameBuilder())->build(new PubMessage('a', 'one')));

            // Act
            $this->forceTick(); // fail and keep frame as failed
            $service->detach();
            $service->start($secondConnection);
            $service->drain(1000);

            // Assert
            self::assertSame(0, $service->getPendingMessages());
            self::assertSame(0, $service->getPendingBytes());
        });
    }

    public function testFrameSizeMatchesWireStringLength(): void
    {
        // Arrange
        $message = new PubMessage('subject', 'payload');

        // Act
        $frame = (new OutboundFrameBuilder())->build($message);

        // Assert
        self::assertSame(strlen($frame->data), $frame->bytes);
        self::assertSame((string) $message, $frame->data);
    }

    private function createConnection(callable $send): ConnectionInterface
    {
        return new class ($send) implements ConnectionInterface {
            public function __construct(callable $send)
            {
                $this->send = $send(...);
            }

            private readonly \Closure $send;

            public function open(\Amp\Cancellation|null $cancellation = null): void
            {
            }

            public function close(): void
            {
            }

            public function isClosed(): bool
            {
                return false;
            }

            public function receive(\Amp\Cancellation|null $cancellation = null): \Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface|null
            {
                return null;
            }

            public function send(\Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface $message): void
            {
                ($this->send)((string) $message);
            }
        };
    }
}
