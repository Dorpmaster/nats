<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Tests\AsyncTestCase;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;

final class ConnectionConnectTest extends AsyncTestCase
{
    public function testWaitForConnected(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $connection = self::createMock(ConnectionInterface::class);
            $connection->method('open')
                ->willReturnCallback(static function(): void {
                    delay(1);
                });

            $configuration = new ClientConfiguration();
            $cancellation = new NullCancellation();
            $eventDispatcher = new EventDispatcher();

            $client = new Client(
                $configuration,
                $cancellation,
                $connection,
                $eventDispatcher,
                $this->logger,
            );

            awaitAll([
                async($client->connect(...)),
                async($client->connect(...)),
                async($client->connect(...)),
            ]);

            self::assertTrue(true);
        });
    }
}
