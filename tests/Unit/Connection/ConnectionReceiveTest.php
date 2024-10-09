<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Tests\AsyncTestCase;
use Psr\Log\LoggerInterface;

final class ConnectionReceiveTest extends AsyncTestCase
{
    public function testSuccessfulReading(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isSocketClosed = true;

            $socket = self::createMock(Socket::class);
            $socket->method('isClosed')
                ->willReturnCallback(static function () use (&$isSocketClosed): bool {
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
                ->willReturnCallback(static function () use ($socket, &$isSocketClosed): Socket {
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

            $this->forceTick();

            // Getting a first message
            $message = $connection->receive();
            self::assertInstanceOf(PingMessage::class, $message);

            // Getting null
            $message = $connection->receive();
            self::assertNull($message);

            $this->forceTick();

            // Connection should be closed
            self::assertTrue($connection->isClosed());
        });
    }
}
