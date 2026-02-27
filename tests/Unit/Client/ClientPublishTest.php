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
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class ClientPublishTest extends TestCase
{
    use AsyncTestTools;

    public function testPublish(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $message = new PubMessage('test', 'payload');

            $connection = self::createMock(ConnectionInterface::class);
            $connection->expects(self::once())
                ->method('send')
                ->with($message)
            ;

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $storage           = self::createStub(SubscriptionStorageInterface::class);
            $configuration     = new ClientConfiguration();
            $cancellation      = new NullCancellation();
            $eventDispatcher   = new EventDispatcher();

            $client = new Client(
                configuration: $configuration,
                cancellation: $cancellation,
                connection: $connection,
                eventDispatcher: $eventDispatcher,
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
            );

            $client->publish($message);
        });
    }

    public function testSendingException(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $message = new PubMessage('test', 'payload');

            $connection = self::createMock(ConnectionInterface::class);
            $connection->expects(self::once())
                ->method('send')
                ->with($message)
                ->willThrowException(new \RuntimeException());

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
            $storage           = self::createStub(SubscriptionStorageInterface::class);

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

            self::expectException(\RuntimeException::class);
            $client->publish($message);
        });
    }
}
