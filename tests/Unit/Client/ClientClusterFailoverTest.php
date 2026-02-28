<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Connection\ServerPoolInterface;
use Dorpmaster\Nats\Domain\Connection\ServerSelectableConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Tests\Support\RecordingDelayStrategy;
use PHPUnit\Framework\TestCase;

final class ClientClusterFailoverTest extends TestCase
{
    public function testReconnectSwitchesToNextServerWhenCurrentAttemptFails(): void
    {
        // Arrange
        $a = new ServerAddress('nats-a', 4222);
        $b = new ServerAddress('nats-b', 4222);

        $pool = self::createMock(ServerPoolInterface::class);
        $pool->method('allServers')->willReturn([$a, $b]);
        $pool->expects(self::exactly(2))
            ->method('getCurrent')
            ->willReturn(null);
        $pool->expects(self::exactly(2))
            ->method('nextServer')
            ->willReturnOnConsecutiveCalls($a, $b);
        $pool->expects(self::once())
            ->method('setCurrent')
            ->with($b);
        $pool->expects(self::once())
            ->method('markDead')
            ->with($a, 10_000);

        $connection  = self::createMock(ServerSelectableConnectionInterface::class);
        $usedServers = [];
        $connection->expects(self::exactly(2))
            ->method('useServer')
            ->willReturnCallback(static function (ServerAddress $server) use (&$usedServers): void {
                $usedServers[] = $server;
            });
        $connection->expects(self::exactly(2))
            ->method('close');
        $connection->expects(self::exactly(2))
            ->method('open')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new ConnectionException('nats-a is down')),
                null,
            );
        $connection->method('isClosed')->willReturn(false);
        $connection->method('receive')->willReturn(null);
        $connection->method('send');

        $client = $this->createClient(
            connection: $connection,
            pool: $pool,
            configuration: new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 2,
                reconnectBackoffInitialMs: 10,
                reconnectBackoffMaxMs: 100,
                reconnectBackoffMultiplier: 2.0,
                reconnectJitterFraction: 0.0,
                deadServerCooldownMs: 10_000,
            ),
        );

        $setState     = \Closure::bind(
            static function (Client $target): void {
                $target->status = ClientState::CONNECTED;
            },
            null,
            Client::class,
        );
        $tryReconnect = \Closure::bind(
            static function (Client $target): bool {
                return $target->tryReconnect(new \RuntimeException('read failed'));
            },
            null,
            Client::class,
        );

        // Act
        $setState($client);
        $result = $tryReconnect($client);

        // Assert
        self::assertTrue($result);
        self::assertSame(ClientState::CONNECTED, $client->getState());
        self::assertEquals([$a, $b], $usedServers);
    }

    public function testReconnectExhaustsAttemptsAcrossServersAndClosesClient(): void
    {
        // Arrange
        $a             = new ServerAddress('nats-a', 4222);
        $b             = new ServerAddress('nats-b', 4222);
        $delayStrategy = new RecordingDelayStrategy();
        $marked        = [];

        $pool = self::createMock(ServerPoolInterface::class);
        $pool->method('allServers')->willReturn([$a, $b]);
        $pool->expects(self::exactly(2))
            ->method('getCurrent')
            ->willReturn(null);
        $pool->expects(self::exactly(2))
            ->method('nextServer')
            ->willReturnOnConsecutiveCalls($a, $b);
        $pool->expects(self::never())
            ->method('setCurrent');
        $pool->expects(self::exactly(2))
            ->method('markDead')
            ->willReturnCallback(static function (ServerAddress $server, int $cooldownMs) use (&$marked): void {
                $marked[] = [$server, $cooldownMs];
            });

        $connection  = self::createMock(ServerSelectableConnectionInterface::class);
        $usedServers = [];
        $connection->expects(self::exactly(2))
            ->method('useServer')
            ->willReturnCallback(static function (ServerAddress $server) use (&$usedServers): void {
                $usedServers[] = $server;
            });
        $connection->expects(self::exactly(2))
            ->method('close');
        $connection->expects(self::exactly(2))
            ->method('open')
            ->willThrowException(new ConnectionException('connect failed'));
        $connection->method('isClosed')->willReturn(true);
        $connection->method('receive')->willReturn(null);
        $connection->method('send');

        $client = $this->createClient(
            connection: $connection,
            pool: $pool,
            delayStrategy: $delayStrategy,
            configuration: new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 2,
                reconnectBackoffInitialMs: 10,
                reconnectBackoffMaxMs: 100,
                reconnectBackoffMultiplier: 2.0,
                reconnectJitterFraction: 0.0,
                deadServerCooldownMs: 10_000,
            ),
        );

        $setState     = \Closure::bind(
            static function (Client $target): void {
                $target->status = ClientState::CONNECTED;
            },
            null,
            Client::class,
        );
        $tryReconnect = \Closure::bind(
            static function (Client $target): bool {
                return $target->tryReconnect(new \RuntimeException('read failed'));
            },
            null,
            Client::class,
        );

        // Act
        $setState($client);
        $result = $tryReconnect($client);

        // Assert
        self::assertFalse($result);
        self::assertSame(ClientState::CLOSED, $client->getState());
        self::assertSame([10, 20], $delayStrategy->delays());
        self::assertEquals([$a, $b], $usedServers);
        self::assertCount(2, $marked);
        self::assertTrue($marked[0][0]->equals($a));
        self::assertTrue($marked[1][0]->equals($b));
        self::assertSame(10_000, $marked[0][1]);
        self::assertSame(10_000, $marked[1][1]);
    }

    private function createClient(
        ServerSelectableConnectionInterface $connection,
        ServerPoolInterface $pool,
        ClientConfiguration $configuration,
        RecordingDelayStrategy|null $delayStrategy = null,
    ): Client {
        $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
        $messageDispatcher->method('dispatch')->willReturn(null);
        $storage = self::createStub(SubscriptionStorageInterface::class);
        $storage->method('all')->willReturn([]);
        $writeBuffer = self::createStub(WriteBufferInterface::class);
        $writeBuffer->method('enqueue')->willReturn(true);

        return new Client(
            configuration: $configuration,
            cancellation: new NullCancellation(),
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: $messageDispatcher,
            storage: $storage,
            logger: null,
            delayStrategy: $delayStrategy ?? new RecordingDelayStrategy(),
            writeBuffer: $writeBuffer,
            serverPool: $pool,
        );
    }
}
