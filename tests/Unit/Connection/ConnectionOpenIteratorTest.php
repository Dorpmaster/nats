<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Amp\DeferredFuture;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Tests\AsyncTestCase;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class ConnectionOpenIteratorTest extends AsyncTestCase
{
    public function testSuccessfulReading(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isSocketClosed = true;

            $socket = self::createMock(Socket::class);
            $socket->method('isClosed')
                ->willReturnCallback(static function() use (&$isSocketClosed): bool {
                    return $isSocketClosed;
                });

            $socket->method('close')
                ->willReturnCallback(static function () use (&$isSocketClosed): void {
                    $isSocketClosed = true;
                });

            $socket->method('read')
                ->willReturnOnConsecutiveCalls(
                    (string) new PingMessage(),
                    null,
                );

            $connector = self::createMock(SocketConnector::class);
            $connector->method('connect')
                ->with('test.nats.local:4222')
                ->willReturnCallback(static function () use($socket, &$isSocketClosed): Socket {
                    $isSocketClosed = false;

                    return $socket;
                });

            $configuration = self::createMock(ConnectionConfigurationInterface::class);
            $configuration->method('getHost')
                ->willReturn('test.nats.local');
            $configuration->method('getPort')
                ->willReturn(4222);
            $configuration->method('getQueueBufferSize')
                ->willReturn(1000);

            $logger = self::createMock(LoggerInterface::class);

            $connection = new Connection(
                $connector,
                $configuration,
                $logger,
            );

            self::assertTrue($connection->isClosed());
            $connection->open();
            self::assertFalse($connection->isClosed());

            // Force an extra tick of the event loop to ensure any callbacks are
            // processed to the event loop handler before start assertions.
            $deferred = new DeferredFuture();
            EventLoop::defer(static fn () => $deferred->complete());
            $deferred->getFuture()->await();

            self::assertTrue($connection->isClosed());
        });
    }

    public function testSocketException(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isSocketClosed = true;

            $socket = self::createMock(Socket::class);
            $socket->method('isClosed')
                ->willReturnCallback(static function() use (&$isSocketClosed): bool {
                    return $isSocketClosed;
                });

            $socket->method('close')
                ->willReturnCallback(static function () use (&$isSocketClosed): void {
                    $isSocketClosed = true;
                });

            $socket->method('read')
                ->willThrowException(new \Exception('Socket Exception', 10));

            $connector = self::createMock(SocketConnector::class);
            $connector->method('connect')
                ->with('test.nats.local:4222')
                ->willReturnCallback(static function () use($socket, &$isSocketClosed): Socket {
                    $isSocketClosed = false;

                    return $socket;
                });

            $configuration = self::createMock(ConnectionConfigurationInterface::class);
            $configuration->method('getHost')
                ->willReturn('test.nats.local');
            $configuration->method('getPort')
                ->willReturn(4222);
            $configuration->method('getQueueBufferSize')
                ->willReturn(1000);

            $logger = self::createMock(LoggerInterface::class);

            $connection = new Connection(
                $connector,
                $configuration,
                $logger,
            );

            self::assertTrue($connection->isClosed());
            $connection->open();
            self::assertFalse($connection->isClosed());

            // Force an extra tick of the event loop to ensure any callbacks are
            // processed to the event loop handler before start assertions.
            $deferred = new DeferredFuture();
            EventLoop::defer(static fn () => $deferred->complete());
            $deferred->getFuture()->await();

            self::assertTrue($connection->isClosed());
        });
    }
}
