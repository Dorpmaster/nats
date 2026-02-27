<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Amp\CancelledException;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConnectionReceiveTest extends TestCase
{
    use AsyncTestTools;

    public function testReceiveReturnsNullOnImmediateEof(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $isSocketClosed = true;

            $socket = self::createMock(Socket::class);
            $socket->method('isClosed')
                ->willReturnCallback(static function () use (&$isSocketClosed): bool {
                    return $isSocketClosed;
                });

            $socket->expects(self::once())
                ->method('close')
                ->willReturnCallback(static function () use (&$isSocketClosed): void {
                    $isSocketClosed = true;
                });

            $socket->expects(self::once())
                ->method('read')
                ->willReturn(null);

            $connection = $this->createConnection($socket, $isSocketClosed);

            // Act
            self::assertTrue($connection->isClosed());
            $connection->open();
            $this->forceTick();
            $message = $connection->receive();

            // Assert
            self::assertNull($message);
            self::assertTrue($connection->isClosed());
        });
    }

    public function testReceivePropagatesReadExceptionAndClosesSocket(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $isSocketClosed = true;

            $socket = self::createMock(Socket::class);
            $socket->method('isClosed')
                ->willReturnCallback(static function () use (&$isSocketClosed): bool {
                    return $isSocketClosed;
                });

            $socket->expects(self::once())
                ->method('close')
                ->willReturnCallback(static function () use (&$isSocketClosed): void {
                    $isSocketClosed = true;
                });

            $socket->expects(self::once())
                ->method('read')
                ->willThrowException(new \RuntimeException('Socket read failure', 55));

            $connection = $this->createConnection($socket, $isSocketClosed);

            // Act
            $connection->open();
            $this->forceTick();

            // Assert
            try {
                $connection->receive();
                self::fail('Expected receive() to throw an exception from queue error');
            } catch (\RuntimeException $exception) {
                self::assertSame('Socket read failure', $exception->getMessage());
                self::assertSame(55, $exception->getCode());
            }

            self::assertTrue($connection->isClosed());
        });
    }

    public function testReceiveReturnsMessageThenNullOnAbruptEof(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $isSocketClosed = true;

            $socket = self::createMock(Socket::class);
            $socket->method('isClosed')
                ->willReturnCallback(static function () use (&$isSocketClosed): bool {
                    return $isSocketClosed;
                });

            $socket->expects(self::once())
                ->method('close')
                ->willReturnCallback(static function () use (&$isSocketClosed): void {
                    $isSocketClosed = true;
                });

            $socket->expects(self::exactly(2))
                ->method('read')
                ->willReturnOnConsecutiveCalls(
                    (string) new PingMessage(),
                    null,
                );

            $connection = $this->createConnection($socket, $isSocketClosed);

            // Act
            $connection->open();
            $this->forceTick();
            $first = $connection->receive();
            $next  = $connection->receive();

            // Assert
            self::assertInstanceOf(PingMessage::class, $first);
            self::assertNull($next);
            self::assertTrue($connection->isClosed());
        });
    }

    public function testReceiveCompletesOnCancelledExceptionAndClosesSocket(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $isSocketClosed = true;

            $socket = self::createMock(Socket::class);
            $socket->method('isClosed')
                ->willReturnCallback(static function () use (&$isSocketClosed): bool {
                    return $isSocketClosed;
                });

            $socket->expects(self::once())
                ->method('close')
                ->willReturnCallback(static function () use (&$isSocketClosed): void {
                    $isSocketClosed = true;
                });

            $socket->expects(self::once())
                ->method('read')
                ->willThrowException(new CancelledException());

            $connection = $this->createConnection($socket, $isSocketClosed);

            // Act
            $connection->open();
            $this->forceTick();
            $message = $connection->receive();

            // Assert
            self::assertNull($message);
            self::assertTrue($connection->isClosed());
        });
    }

    public function testReceiveStopsAfterParserFatalErrorAndClosesSocket(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $isSocketClosed = true;

            $socket = self::createMock(Socket::class);
            $socket->method('isClosed')
                ->willReturnCallback(static function () use (&$isSocketClosed): bool {
                    return $isSocketClosed;
                });

            $socket->expects(self::once())
                ->method('close')
                ->willReturnCallback(static function () use (&$isSocketClosed): void {
                    $isSocketClosed = true;
                });

            $socket->expects(self::once())
                ->method('read')
                ->willReturn("PUNG\r\n");

            $connection = $this->createConnection($socket, $isSocketClosed);

            // Act
            $connection->open();
            $this->forceTick();

            // Assert
            try {
                $connection->receive();
                self::fail('Expected receive() to fail on parser error');
            } catch (\RuntimeException $exception) {
                self::assertSame('Unknown message type "PUNG"', $exception->getMessage());
            }

            self::assertTrue($connection->isClosed());
        });
    }

    private function createConnection(Socket $socket, bool &$isSocketClosed): Connection
    {
        $connector = self::createMock(SocketConnector::class);
        $connector->expects(self::once())
            ->method('connect')
            ->willReturnCallback(static function (string $uri) use ($socket, &$isSocketClosed): Socket {
                self::assertSame('test.nats.local:4222', $uri);
                $isSocketClosed = false;

                return $socket;
            });

        $configuration = self::createStub(ConnectionConfigurationInterface::class);
        $configuration->method('getHost')
            ->willReturn('test.nats.local');
        $configuration->method('getPort')
            ->willReturn(4222);
        $configuration->method('getQueueBufferSize')
            ->willReturn(1000);

        $logger = self::createStub(LoggerInterface::class);

        return new Connection(
            $connector,
            $configuration,
            $logger,
        );
    }
}
