<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\SubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class ClientSubscribeTest extends TestCase
{
    use AsyncTestTools;

    public function testSubscribe(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $connection = self::createMock(ConnectionInterface::class);
            $connection->expects(self::once())
                ->method('send');

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);

            $storage = self::createMock(SubscriptionStorageInterface::class);
            $storage->expects(self::once())
                ->method('add');
            $storage->expects(self::never())
                ->method('remove');

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

            $sid = $client->subscribe('test', static fn() => null);

            self::assertNotEmpty($sid);
        });
    }

    public function testSendingException(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $connection = self::createMock(ConnectionInterface::class);
            $connection->expects(self::once())
                ->method('send')
                ->willThrowException(new \RuntimeException());

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);

            $storage = self::createMock(SubscriptionStorageInterface::class);
            $storage->expects(self::once())
                ->method('add');
            $storage->expects(self::once())
                ->method('remove');

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
            $client->subscribe('test', static fn() => null);
        });
    }

    public function testSubscribeUsesInjectedSubscriptionIdHelper(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $connection = self::createMock(ConnectionInterface::class);
            $connection->expects(self::once())
                ->method('send')
                ->with(self::callback(static function (mixed $message): bool {
                    self::assertInstanceOf(SubMessage::class, $message);
                    self::assertSame('customsid', $message->getSid());

                    return true;
                }));

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);

            $storage = self::createMock(SubscriptionStorageInterface::class);
            $storage->expects(self::once())
                ->method('add');
            $storage->expects(self::never())
                ->method('remove');

            $idHelper = self::createStub(SubscriptionIdHelperInterface::class);
            $idHelper->method('generateId')
                ->willReturn('customsid');

            $client = new Client(
                configuration: new ClientConfiguration(),
                cancellation: new NullCancellation(),
                connection: $connection,
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
                subscriptionIdHelper: $idHelper,
            );

            // Act
            $sid = $client->subscribe('test', static fn() => null);

            // Assert
            self::assertSame('customsid', $sid);
        });
    }
}
