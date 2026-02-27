<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\CancelledException;
use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\ReconnectDelayHelperInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\RecordingDelayStrategy;
use PHPUnit\Framework\TestCase;

final class ClientReconnectTest extends TestCase
{
    use AsyncTestTools;

    public function testEofTriggersReconnect(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $openCalls = 0;
            $closed    = true;
            $reads     = 0;

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function () use (&$openCalls, &$closed): void {
                    $openCalls++;
                    if ($openCalls >= 3) {
                        throw new \RuntimeException('reconnect failed');
                    }
                    $closed = false;
                });
            $connection->method('close')
                ->willReturnCallback(static function () use (&$closed): void {
                    $closed = true;
                });
            $connection->method('isClosed')
                ->willReturnCallback(static function () use (&$closed): bool {
                    return $closed;
                });
            $connection->method('receive')
                ->willReturnCallback(static function () use (&$reads, &$closed): null|PingMessage {
                    $reads++;
                    if ($reads === 1) {
                        $closed = true;

                        return null;
                    }
                    throw new \RuntimeException('stop loop');
                });
            $connection->method('send');

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $messageDispatcher->method('dispatch')->willReturn(null);

            $storage = self::createStub(SubscriptionStorageInterface::class);
            $storage->method('all')->willReturn([]);

            $client = new Client(
                configuration: new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 1,
                    reconnectJitterFraction: 0.0,
                ),
                cancellation: new NullCancellation(),
                connection: $connection,
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
            );

            // Act
            $client->connect();
            $this->forceTick();
            $this->forceTick();

            // Assert
            self::assertGreaterThanOrEqual(2, $openCalls);
        });
    }

    public function testCancelledExceptionStopsProcessingWithoutReconnect(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $openCalls = 0;
            $reads     = 0;

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function () use (&$openCalls): void {
                    $openCalls++;
                });
            $connection->method('close');
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')
                ->willReturnCallback(static function () use (&$reads): PingMessage {
                    $reads++;
                    if ($reads === 1) {
                        throw new CancelledException();
                    }
                    throw new \RuntimeException('stop loop');
                });
            $connection->method('send');

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $messageDispatcher->method('dispatch')->willReturn(null);

            $storage = self::createStub(SubscriptionStorageInterface::class);
            $storage->method('all')->willReturn([]);

            $client = new Client(
                configuration: new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 3,
                    reconnectJitterFraction: 0.0,
                ),
                cancellation: new NullCancellation(),
                connection: $connection,
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
            );

            // Act
            $client->connect();
            $this->forceTick();
            $client->disconnect();

            // Assert
            self::assertSame(1, $openCalls);
        });
    }

    public function testParserFatalTriggersReconnect(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $openCalls = 0;
            $reads     = 0;

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function () use (&$openCalls): void {
                    $openCalls++;
                    if ($openCalls >= 3) {
                        throw new \RuntimeException('reconnect failed');
                    }
                });
            $connection->method('close');
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')
                ->willReturnCallback(static function () use (&$reads): PingMessage {
                    $reads++;
                    if ($reads === 1) {
                        throw new \RuntimeException('Unknown message type "PUNG"');
                    }
                    throw new \RuntimeException('stop loop');
                });
            $connection->method('send');

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $messageDispatcher->method('dispatch')->willReturn(null);

            $storage = self::createStub(SubscriptionStorageInterface::class);
            $storage->method('all')->willReturn([]);

            $client = new Client(
                configuration: new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 1,
                    reconnectJitterFraction: 0.0,
                ),
                cancellation: new NullCancellation(),
                connection: $connection,
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
            );

            // Act
            $client->connect();
            $this->forceTick();
            $this->forceTick();

            // Assert
            self::assertGreaterThanOrEqual(2, $openCalls);
        });
    }

    public function testMaxReconnectAttemptsRespectedAndBackoffInvoked(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $openCalls     = 0;
            $reads         = 0;
            $delayStrategy = new RecordingDelayStrategy();

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function () use (&$openCalls): void {
                    $openCalls++;
                    if ($openCalls > 1) {
                        throw new \RuntimeException('reconnect failed');
                    }
                });
            $connection->method('close');
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')
                ->willReturnCallback(static function () use (&$reads): PingMessage {
                    $reads++;
                    if ($reads === 1) {
                        throw new \RuntimeException('read failed');
                    }

                    return new PingMessage();
                });
            $connection->method('send');

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $messageDispatcher->method('dispatch')->willReturn(null);

            $storage = self::createStub(SubscriptionStorageInterface::class);
            $storage->method('all')->willReturn([]);

            $client = new Client(
                configuration: new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 2,
                    reconnectBackoffInitialMs: 10,
                    reconnectBackoffMaxMs: 100,
                    reconnectBackoffMultiplier: 2.0,
                    reconnectJitterFraction: 0.0,
                ),
                cancellation: new NullCancellation(),
                connection: $connection,
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
                delayStrategy: $delayStrategy,
            );

            // Act
            $client->connect();
            $this->forceTick();
            $this->forceTick();

            // Assert
            self::assertSame(3, $openCalls);
            self::assertSame([10, 20], $delayStrategy->delays());
        });
    }

    public function testReconnectUsesInjectedDelayHelper(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $openCalls     = 0;
            $reads         = 0;
            $delayStrategy = new RecordingDelayStrategy();

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function () use (&$openCalls): void {
                    $openCalls++;
                    if ($openCalls > 1) {
                        throw new \RuntimeException('reconnect failed');
                    }
                });
            $connection->method('close');
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')
                ->willReturnCallback(static function () use (&$reads): PingMessage {
                    $reads++;
                    if ($reads === 1) {
                        throw new \RuntimeException('read failed');
                    }

                    return new PingMessage();
                });
            $connection->method('send');

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $messageDispatcher->method('dispatch')->willReturn(null);

            $storage = self::createStub(SubscriptionStorageInterface::class);
            $storage->method('all')->willReturn([]);

            $delayHelper = self::createStub(ReconnectDelayHelperInterface::class);
            $delayHelper->method('calculateDelayMs')
                ->willReturnOnConsecutiveCalls(7, 11);

            $client = new Client(
                configuration: new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 2,
                    reconnectBackoffInitialMs: 10,
                    reconnectBackoffMaxMs: 100,
                    reconnectBackoffMultiplier: 2.0,
                    reconnectJitterFraction: 0.0,
                ),
                cancellation: new NullCancellation(),
                connection: $connection,
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
                delayStrategy: $delayStrategy,
                reconnectDelayHelper: $delayHelper,
            );

            // Act
            $client->connect();
            $this->forceTick();
            $this->forceTick();

            // Assert
            self::assertSame(3, $openCalls);
            self::assertSame([7, 11], $delayStrategy->delays());
        });
    }
}
