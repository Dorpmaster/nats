<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PongMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConnectionSendTest extends TestCase
{
    public function testOpen(): void
    {
        // Arrange
        $message = new PongMessage();

        $socket = self::createMock(Socket::class);
        $socket->expects(self::once())
            ->method('write')
            ->with((string) $message);

        $connector = self::createMock(SocketConnector::class);
        $connector->expects(self::once())
            ->method('connect')
            ->willReturnCallback(static function (string $uri) use ($socket): Socket {
                self::assertSame('test.nats.local:4222', $uri);

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

        $connection = new Connection(
            $connector,
            $configuration,
            $logger,
        );

        // Act
        self::assertTrue($connection->isClosed());
        $connection->open();
        self::assertFalse($connection->isClosed());
        $connection->send($message);

        // Assert
        self::assertFalse($connection->isClosed());
    }

    public function testSocketException(): void
    {
        // Arrange
        $message = new PingMessage();

        $socket = self::createMock(Socket::class);
        $socket->expects(self::once())
            ->method('write')
            ->with((string) $message)
            ->willThrowException(new \Exception('Socket exception', 10));

        $connector = self::createMock(SocketConnector::class);
        $connector->expects(self::once())
            ->method('connect')
            ->willReturnCallback(static function (string $uri) use ($socket): Socket {
                self::assertSame('test.nats.local:4222', $uri);

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

        $connection = new Connection(
            $connector,
            $configuration,
            $logger,
        );

        // Act
        self::assertTrue($connection->isClosed());
        $connection->open();
        self::assertFalse($connection->isClosed());

        // Assert
        self::expectException(ConnectionException::class);
        self::expectExceptionMessage('Socket exception');
        self::expectExceptionCode(10);
        $connection->send($message);
    }

    public function testClosed(): void
    {
        // Arrange
        $message = new PingMessage();

        $socket = self::createMock(Socket::class);
        $socket->expects(self::never())
            ->method('write');

        $connector = self::createMock(SocketConnector::class);
        $connector->expects(self::never())
            ->method('connect')
            ->willReturn($socket);

        $configuration = self::createStub(ConnectionConfigurationInterface::class);
        $logger        = self::createStub(LoggerInterface::class);

        $connection = new Connection(
            $connector,
            $configuration,
            $logger,
        );

        // Act
        self::assertTrue($connection->isClosed());
        $connection->send($message);

        // Assert
        self::assertTrue($connection->isClosed());
    }

    public function testSendAfterCloseDoesNothing(): void
    {
        // Arrange
        $message = new PingMessage();

        $socket = self::createMock(Socket::class);
        $socket->expects(self::once())
            ->method('write')
            ->with((string) $message);

        $connector = self::createMock(SocketConnector::class);
        $connector->expects(self::once())
            ->method('connect')
            ->willReturnCallback(static function (string $uri) use ($socket): Socket {
                self::assertSame('test.nats.local:4222', $uri);

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

        $connection = new Connection(
            $connector,
            $configuration,
            $logger,
        );

        // Act
        $connection->open();
        $connection->send($message);
        $connection->close();
        $connection->send($message);

        // Assert
        self::assertTrue($connection->isClosed());
    }

    public function testSendDoesNothingWhenSocketExistsButMarkedClosed(): void
    {
        // Arrange
        $message = new PingMessage();

        $socket = self::createMock(Socket::class);
        $socket->method('isClosed')
            ->willReturn(true);
        $socket->expects(self::never())
            ->method('write');

        $connector = self::createMock(SocketConnector::class);
        $connector->expects(self::once())
            ->method('connect')
            ->willReturnCallback(static function (string $uri) use ($socket): Socket {
                self::assertSame('test.nats.local:4222', $uri);

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

        $connection = new Connection(
            $connector,
            $configuration,
            $logger,
        );

        // Act
        $connection->open();
        $connection->send($message);

        // Assert
        self::assertTrue($connection->isClosed());
    }
}
