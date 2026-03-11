<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\UnSubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class ClientUnsubscribeTest extends TestCase
{
    use AsyncTestTools;

    public function testSubscribe(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $sid = 'aabbcc';

            $writeBuffer = self::createMock(WriteBufferInterface::class);
            $writeBuffer->method('setFailureHandler');
            $writeBuffer->expects(self::once())
                ->method('enqueue')
                ->with(self::callback(static function (mixed $frame) use ($sid): bool {
                    self::assertInstanceOf(UnSubMessage::class, $frame->message);
                    self::assertSame($sid, $frame->message->getSid());

                    return true;
                }))
                ->willReturn(true);

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);

            $storage = self::createMock(SubscriptionStorageInterface::class);
            $storage->expects(self::once())
                ->method('remove')
                ->with($sid)
            ;

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

            $client->unsubscribe($sid);
        });
    }

    public function testSendingException(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            $sid = 'aabbcc';

            $writeBuffer = self::createMock(WriteBufferInterface::class);
            $writeBuffer->method('setFailureHandler');
            $writeBuffer->expects(self::once())
                ->method('enqueue')
                ->willThrowException(new \RuntimeException());

            $messageDispatcher = self::createStub(MessageDispatcherInterface::class);

            $storage = self::createMock(SubscriptionStorageInterface::class);
            $storage->expects(self::once())
                ->method('remove')
                ->with($sid);

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
            $client->unsubscribe($sid);
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
