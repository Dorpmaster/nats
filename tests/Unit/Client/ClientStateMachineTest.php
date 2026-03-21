<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class ClientStateMachineTest extends TestCase
{
    use AsyncTestTools;

    public function testIllegalTransitionThrows(): void
    {
        // Arrange
        $client = $this->createClientWithConnection(self::createStub(ConnectionInterface::class));
        $invoke = \Closure::bind(
            static function (Client $target): void {
                $target->transitionTo(ClientState::RECONNECTING);
            },
            null,
            Client::class,
        );

        // Assert
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('Illegal state transition: NEW -> RECONNECTING');

        // Act
        $invoke($client);
    }

    public function testOpenTransitionsNewToConnected(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            // Arrange
            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open');
            $connection->method('close');
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')->willReturn(null);
            $connection->method('send');

            $client = $this->createClientWithConnection($connection);

            // Act
            $client->connect();
            $completeHandshake = \Closure::bind(
                static function (Client $target): void {
                    $target->completeHandshake();
                },
                null,
                Client::class,
            );
            $completeHandshake($client);

            // Assert
            self::assertSame(ClientState::CONNECTED, $client->getState());

            $client->disconnect();
        });
    }

    public function testReconnectTransitionsConnectedToReconnectingToConnected(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            // Arrange
            $events     = [];
            $dispatcher = new EventDispatcher();
            $dispatcher->subscribe('connectionStatusChanged', static function (string $event, mixed $payload) use (&$events): void {
                $events[] = $payload;
            });

            $openCalls    = 0;
            $receiveCalls = 0;
            $closed       = false;
            $connection   = self::createStub(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function () use (&$openCalls, &$closed): void {
                    $openCalls++;
                    if ($openCalls > 2) {
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
                ->willReturnCallback(static function () use (&$receiveCalls, &$closed): null|NatsProtocolMessageInterface {
                    $receiveCalls++;
                    if ($receiveCalls === 1) {
                        $closed = true;

                        return null;
                    }
                    throw new \RuntimeException('stop');
                });
            $connection->method('send');

            $client = $this->createClientWithConnection(
                $connection,
                $dispatcher,
                reconnectEnabled: true,
                maxReconnectAttempts: 1,
            );

            // Act
            $client->connect();
            $completeHandshake = \Closure::bind(
                static function (Client $target): void {
                    $target->completeHandshake();
                },
                null,
                Client::class,
            );
            $completeHandshake($client);
            $this->forceTick();
            $this->forceTick();
            $client->disconnect();

            // Assert
            self::assertContains(ClientState::RECONNECTING, $events);
            self::assertContains(ClientState::CONNECTED, $events);
        });
    }

    public function testCloseTransitionsToClosedAndIsIdempotent(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            // Arrange
            $closeCalls = 0;
            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open');
            $connection->method('close')
                ->willReturnCallback(static function () use (&$closeCalls): void {
                    $closeCalls++;
                });
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')->willReturn(new PingMessage());
            $connection->method('send');

            $client = $this->createClientWithConnection($connection);
            $client->connect();

            // Act
            $client->disconnect();
            $client->disconnect();

            // Assert
            self::assertSame(ClientState::CLOSED, $client->getState());
            self::assertGreaterThanOrEqual(1, $closeCalls);
        });
    }

    public function testDrainSetsStateToDrainingThenClosed(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            // Arrange
            $events     = [];
            $dispatcher = new EventDispatcher();
            $dispatcher->subscribe('connectionStatusChanged', static function (string $event, mixed $payload) use (&$events): void {
                $events[] = $payload;
            });

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open');
            $connection->method('close');
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')->willReturn(null);
            $connection->method('send');

            $client    = $this->createClientWithConnection($connection, $dispatcher);
            $setStatus = \Closure::bind(
                static function (Client $target): void {
                    $target->status = ClientState::CONNECTED;
                },
                null,
                Client::class,
            );
            $setStatus($client);

            // Act
            $client->drain();
            $this->forceTick();

            // Assert
            self::assertSame(ClientState::CLOSED, $client->getState());
            self::assertContains(ClientState::DRAINING, $events);
            self::assertContains(ClientState::CLOSED, $events);
        });
    }

    public function testPublishDuringDrainFailsPredictably(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            // Arrange
            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open');
            $connection->method('close');
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')->willReturn(null);
            $connection->method('send');

            $client = $this->createClientWithConnection($connection);
            $client->connect();

            $setStatus = \Closure::bind(
                static function (Client $target): void {
                    $target->status = ClientState::DRAINING;
                },
                null,
                Client::class,
            );
            $setStatus($client);

            // Assert
            self::expectException(\Dorpmaster\Nats\Domain\Connection\ConnectionException::class);
            self::expectExceptionMessage('Could not publish while client state is DRAINING');

            // Act
            $client->publish(new \Dorpmaster\Nats\Protocol\PubMessage('test', 'payload'));
        });
    }

    private function createClientWithConnection(
        ConnectionInterface $connection,
        EventDispatcher|null $eventDispatcher = null,
        bool $reconnectEnabled = false,
        int|null $maxReconnectAttempts = 10,
    ): Client {
        $eventDispatcher ??= new EventDispatcher();
        $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
        $messageDispatcher->method('dispatch')->willReturn(null);
        $storage = self::createStub(SubscriptionStorageInterface::class);
        $storage->method('all')->willReturn([]);

        return new Client(
            configuration: new ClientConfiguration(
                reconnectEnabled: $reconnectEnabled,
                maxReconnectAttempts: $maxReconnectAttempts,
                reconnectJitterFraction: 0.0,
            ),
            cancellation: new NullCancellation(),
            connection: $connection,
            eventDispatcher: $eventDispatcher,
            messageDispatcher: $messageDispatcher,
            storage: $storage,
            logger: $this->logger,
        );
    }
}
