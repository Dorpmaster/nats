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
        $message = new PongMessage();

        $socket = self::createMock(Socket::class);
        $socket->expects(self::once())
            ->method('write')
            ->with((string) $message);

        $connector = self::createMock(SocketConnector::class);
        $connector->method('connect')
            ->with('test.nats.local:4222')
            ->willReturn($socket);

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

        $connection->send($message);
    }

    public function testSocketException(): void
    {
        $message = new PingMessage();

        $socket = self::createMock(Socket::class);
        $socket->expects(self::once())
            ->method('write')
            ->with((string) $message)
            ->willThrowException(new \Exception('Socket exception', 10));

        $connector = self::createMock(SocketConnector::class);
        $connector->method('connect')
            ->with('test.nats.local:4222')
            ->willReturn($socket);

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

        self::expectException(ConnectionException::class);
        self::expectExceptionMessage('Socket exception');
        self::expectExceptionCode(10);
        $connection->open();

        $connection->send($message);
    }

    public function testClosed(): void
    {
        $message = new PingMessage();

        $socket = self::createMock(Socket::class);
        $socket->expects(self::never())
            ->method('write');

        $connector = self::createMock(SocketConnector::class);
        $connector->method('connect')
            ->with('test.nats.local:4222')
            ->willReturn($socket);

        $configuration = self::createMock(ConnectionConfigurationInterface::class);
        $logger        = self::createMock(LoggerInterface::class);

        $connection = new Connection(
            $connector,
            $configuration,
            $logger,
        );

        self::assertTrue($connection->isClosed());

        $connection->send($message);
    }
}
