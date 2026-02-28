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
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class ClientPublishTest extends TestCase
{
    use AsyncTestTools;

    public function testPublish(): void
    {
        // Arrange
        $this->setTimeout(30);
        $this->runAsyncTest(function (): void {
            $message = new PubMessage('test', 'payload');

            $writeBuffer = self::createMock(WriteBufferInterface::class);
            $writeBuffer->method('setFailureHandler');
            $writeBuffer->expects(self::once())
                ->method('enqueue')
                ->with(self::callback(static function (mixed $frame) use ($message): bool {
                    self::assertSame((string) $message, $frame->data);
                    self::assertSame(strlen((string) $message), $frame->bytes);

                    return true;
                }))
                ->willReturn(true);

            $connection = self::createStub(ConnectionInterface::class);

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
                writeBuffer: $writeBuffer,
            );

            $setStatus = \Closure::bind(
                static function (Client $target): void {
                    $target->status = ClientState::CONNECTED;
                },
                null,
                Client::class,
            );
            $setStatus($client);

            // Act
            $client->publish($message);
        });
    }

    public function testSendingException(): void
    {
        // Arrange
        $this->setTimeout(30);
        $this->runAsyncTest(function (): void {
            $message = new PubMessage('test', 'payload');

            $writeBuffer = self::createMock(WriteBufferInterface::class);
            $writeBuffer->method('setFailureHandler');
            $writeBuffer->expects(self::once())
                ->method('enqueue')
                ->willThrowException(new \RuntimeException());

            $connection        = self::createStub(ConnectionInterface::class);
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
                writeBuffer: $writeBuffer,
            );

            $setStatus = \Closure::bind(
                static function (Client $target): void {
                    $target->status = ClientState::CONNECTED;
                },
                null,
                Client::class,
            );
            $setStatus($client);

            // Assert
            self::expectException(\RuntimeException::class);

            // Act
            $client->publish($message);
        });
    }
}
