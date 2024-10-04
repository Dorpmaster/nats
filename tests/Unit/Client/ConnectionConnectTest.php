<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Tests\AsyncTestCase;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;
use function Amp\ByteStream\getStderr;

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

            $cancellation = new NullCancellation();
            $eventDispatcher = new EventDispatcher();

            $logHandler = new StreamHandler(getStderr());
            $logHandler->pushProcessor(new PsrLogMessageProcessor());
            $logHandler->setFormatter(new ConsoleFormatter());

            $logger = new Logger('ConnectionConnectTest.WaitForConnected');
            $logger->pushHandler($logHandler);

            $client = new Client(
                $cancellation,
                $connection,
                $eventDispatcher,
                $logger,
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
