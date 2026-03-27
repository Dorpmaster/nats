<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\SubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class ClientSubscribeTest extends TestCase
{
    use AsyncTestTools;

    public function testSubscribeWithoutQueueGroupGeneratesRegularSUB(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $writeBuffer = self::createMock(WriteBufferInterface::class);
            $writeBuffer->method('setFailureHandler');
            $writeBuffer->expects(self::once())
                ->method('enqueue')
                ->with(self::callback(static function (mixed $frame): bool {
                    self::assertInstanceOf(SubMessage::class, $frame->message);
                    self::assertNull($frame->message->getQueueGroup());

                    return true;
                }))
                ->willReturn(true);

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
                connection: self::createStub(ConnectionInterface::class),
                eventDispatcher: $eventDispatcher,
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
                writeBuffer: $writeBuffer,
            );
            $this->setState($client, ClientState::CONNECTED);

            $sid = $client->subscribe('test', static fn() => null);

            self::assertNotEmpty($sid);
        });
    }

    public function testSubscribeWithQueueGroupGeneratesQueueSUB(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $writeBuffer = self::createMock(WriteBufferInterface::class);
            $writeBuffer->method('setFailureHandler');
            $writeBuffer->expects(self::once())
                ->method('enqueue')
                ->with(self::callback(static function (mixed $frame): bool {
                    self::assertInstanceOf(SubMessage::class, $frame->message);
                    self::assertSame('workers', $frame->message->getQueueGroup());
                    self::assertStringStartsWith('SUB test workers ', (string) $frame->message);

                    return true;
                }))
                ->willReturn(true);

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);

            $storage = self::createMock(SubscriptionStorageInterface::class);
            $storage->expects(self::once())
                ->method('add')
                ->with(
                    self::isString(),
                    self::isInstanceOf(\Closure::class),
                    'workers',
                );
            $storage->expects(self::never())
                ->method('remove');

            $client = new Client(
                configuration: new ClientConfiguration(),
                cancellation: new NullCancellation(),
                connection: self::createStub(ConnectionInterface::class),
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
                writeBuffer: $writeBuffer,
            );
            $this->setState($client, ClientState::CONNECTED);

            $sid = $client->subscribe('test', static fn() => null, 'workers');

            self::assertNotEmpty($sid);
        });
    }

    public function testSendingException(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $writeBuffer = self::createMock(WriteBufferInterface::class);
            $writeBuffer->method('setFailureHandler');
            $writeBuffer->expects(self::once())
                ->method('enqueue')
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
                connection: self::createStub(ConnectionInterface::class),
                eventDispatcher: $eventDispatcher,
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
                writeBuffer: $writeBuffer,
            );
            $this->setState($client, ClientState::CONNECTED);

            self::expectException(\RuntimeException::class);
            $client->subscribe('test', static fn() => null);
        });
    }

    public function testSubscribeUsesInjectedSubscriptionIdHelper(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $writeBuffer = self::createMock(WriteBufferInterface::class);
            $writeBuffer->method('setFailureHandler');
            $writeBuffer->expects(self::once())
                ->method('enqueue')
                ->with(self::callback(static function (mixed $frame): bool {
                    self::assertInstanceOf(SubMessage::class, $frame->message);
                    self::assertSame('customsid', $frame->message->getSid());

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
                connection: self::createStub(ConnectionInterface::class),
                eventDispatcher: new EventDispatcher(),
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
                subscriptionIdHelper: $idHelper,
                writeBuffer: $writeBuffer,
            );
            $this->setState($client, ClientState::CONNECTED);

            // Act
            $sid = $client->subscribe('test', static fn() => null);

            // Assert
            self::assertSame('customsid', $sid);
        });
    }

    private function setState(Client $client, ClientState $state): void
    {
        $setStatus = \Closure::bind(
            static function (Client $target, ClientState $state): void {
                $target->status = $state;
            },
            null,
            Client::class,
        );
        $setStatus($client, $state);
    }
}
