<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PongMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

final class ClientConnectTest extends TestCase
{
    use AsyncTestTools;

    public function testWaitForConnected(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $isClosed = true;

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function () use (&$isClosed): void {
                    delay(0.1);
                    $isClosed = false;
                });

            $connection->method('close')
                ->willReturnCallback(static function () use (&$isClosed): void {
                    $isClosed = true;
                });

            $connection->method('isClosed')
                ->willReturnCallback(static function () use (&$isClosed): bool {
                    return $isClosed;
                });

            $connection->method('receive')
                ->willReturnCallback(static function (): NatsProtocolMessageInterface {
                    return async(static function (): NatsProtocolMessageInterface {
                        return new PingMessage();
                    })->await();
                });

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $messageDispatcher->method('dispatch')
                ->willReturn(new PongMessage());

            $storage = self::createStub(SubscriptionStorageInterface::class);

            $configuration   = new ClientConfiguration();
            $cancellation    = new NullCancellation();
            $eventDispatcher = new EventDispatcher();

            $client = new Client(
                configuration: $configuration,
                cancellation: $cancellation,
                connection: $connection,
                eventDispatcher: $eventDispatcher,
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
            );

            await([
                async($client->connect(...)),
                async($client->connect(...)),
                async($client->connect(...)),
            ]);

            $client->disconnect();

            self::assertTrue(true);
        });
    }
}
