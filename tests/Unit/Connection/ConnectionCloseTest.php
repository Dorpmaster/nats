<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConnectionCloseTest extends TestCase
{
    public function testClose(): void
    {
        $socket = self::createMock(Socket::class);

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
        $connection->close();
        self::assertTrue($connection->isClosed());
    }
}
