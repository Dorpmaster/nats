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
use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PongMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

final class ClientDisconnectTest extends TestCase
{
    use AsyncTestTools;

    private const string INFO_PAYLOAD = '{"server_id":"id","server_name":"nats","version":"1","go":"go","host":"127.0.0.1","port":4222,"headers":true,"max_payload":1024,"proto":1}';

    public function testWaitForDisconnected(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $isClosed     = true;
            $receiveCalls = 0;

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function () use (&$isClosed): void {
                    $isClosed = false;
                });

            $connection = self::createStub(ConnectionInterface::class);
            $connection->method('close')
                ->willReturnCallback(static function () use (&$isClosed): void {
                    delay(0.1);
                    $isClosed = true;
                });

            $connection->method('isClosed')
                ->willReturnCallback(static function () use (&$isClosed): bool {
                    return $isClosed;
                });

            $connection->method('receive')
                ->willReturnCallback(static function () use (&$receiveCalls): NatsProtocolMessageInterface {
                    return async(static function () use (&$receiveCalls): NatsProtocolMessageInterface {
                        return match ($receiveCalls++) {
                            0 => new InfoMessage(self::INFO_PAYLOAD),
                            default => new PongMessage(),
                        };
                    })->await();
                });

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $messageDispatcher->method('dispatch')
                ->willReturnOnConsecutiveCalls(
                    new ConnectMessage(new ConnectInfo(false, false, false, 'php', PHP_VERSION)),
                    null,
                );
            $storage         = self::createStub(SubscriptionStorageInterface::class);
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

            $client->connect();

            await([
                async($client->disconnect(...)),
                async($client->disconnect(...)),
                async($client->disconnect(...)),
            ]);

            self::assertTrue(true);
        });
    }
}
