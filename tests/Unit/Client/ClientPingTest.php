<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\CancelledException;
use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\FakePingService;
use PHPUnit\Framework\TestCase;

final class ClientPingTest extends TestCase
{
    use AsyncTestTools;

    public function testPingTimeoutTriggersReconnectWhenEnabled(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $openCalls  = 0;
            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open')->willReturnCallback(static function () use (&$openCalls): void {
                $openCalls++;
            });
            $connection->method('close');
            $connection->method('isClosed')->willReturn(false);
            $connection->method('receive')->willThrowException(new CancelledException());
            $connection->method('send');

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $messageDispatcher->method('dispatch')->willReturn(null);
            $storage = self::createStub(SubscriptionStorageInterface::class);
            $storage->method('all')->willReturn([]);
            $pingService = new FakePingService();

            $client = new Client(
                configuration: new ClientConfiguration(
                    reconnectEnabled: true,
                    pingEnabled: true,
                    pingReconnectOnTimeout: true,
                    reconnectJitterFraction: 0.0,
                ),
                cancellation: new NullCancellation(),
                connection: $connection,
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
                pingService: $pingService,
            );

            // Act
            $client->connect();
            $this->forceTick();
            $pingService->triggerTimeout();
            $this->forceTick();

            // Assert
            self::assertGreaterThanOrEqual(2, $openCalls);
            self::assertGreaterThanOrEqual(2, $pingService->startCalls());
        });
    }
}
