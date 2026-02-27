<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\DeferredCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class ClientTest extends TestCase
{
    use AsyncTestTools;

    public function testWaitForTermination(): void
    {
        $this->setTimeout(3);
        $this->runAsyncTest(function () {
            $connection           = self::createStub(ConnectionInterface::class);
            $messageDispatcher    = self::createStub(MessageDispatcherInterface::class);
            $storage              = self::createStub(SubscriptionStorageInterface::class);
            $configuration        = new ClientConfiguration();
            $deferredCancellation = new DeferredCancellation();
            $cancellation         = $deferredCancellation->getCancellation();
            $eventDispatcher      = new EventDispatcher();

            $client = new Client(
                configuration: $configuration,
                cancellation: $cancellation,
                connection: $connection,
                eventDispatcher: $eventDispatcher,
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
            );

            async(static fn() => $deferredCancellation->cancel());

            $client->waitForTermination();

            self::assertTrue(true);
        });
    }
}
