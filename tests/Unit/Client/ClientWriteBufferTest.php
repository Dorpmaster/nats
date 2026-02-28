<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\WriteBufferPolicy;
use Dorpmaster\Nats\Domain\Client\ClientNotConnectedException;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferOverflowException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\PubMessage;
use PHPUnit\Framework\TestCase;

final class ClientWriteBufferTest extends TestCase
{
    public function testPublishDelegatesToWriteBufferWhenConnected(): void
    {
        // Arrange
        $message = new PubMessage('subject', 'payload');

        $writeBuffer = self::createMock(WriteBufferInterface::class);
        $writeBuffer->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(static function (mixed $frame) use ($message): bool {
                self::assertSame((string) $message, $frame->data);
                self::assertSame(strlen((string) $message), $frame->bytes);

                return true;
            }))
            ->willReturn(true);
        $writeBuffer->method('setFailureHandler');

        $client = $this->createClient($writeBuffer);
        $this->setState($client, ClientState::CONNECTED);

        // Act
        $client->publish($message);
    }

    public function testOverflowExceptionBubblesUpFromWriteBuffer(): void
    {
        // Arrange
        $writeBuffer = self::createMock(WriteBufferInterface::class);
        $writeBuffer->expects(self::once())
            ->method('enqueue')
            ->willThrowException(new WriteBufferOverflowException('overflow'));
        $writeBuffer->method('setFailureHandler');

        $client = $this->createClient($writeBuffer);
        $this->setState($client, ClientState::CONNECTED);

        // Assert
        self::expectException(WriteBufferOverflowException::class);

        // Act
        $client->publish(new PubMessage('subject', 'payload'));
    }

    public function testPublishInReconnectingFailsWhenBufferDisabled(): void
    {
        // Arrange
        $writeBuffer = self::createMock(WriteBufferInterface::class);
        $writeBuffer->expects(self::never())->method('enqueue');
        $writeBuffer->method('setFailureHandler');

        $client = $this->createClient(
            $writeBuffer,
            new ClientConfiguration(bufferWhileReconnecting: false),
        );
        $this->setState($client, ClientState::RECONNECTING);

        // Assert
        self::expectException(ClientNotConnectedException::class);

        // Act
        $client->publish(new PubMessage('subject', 'payload'));
    }

    public function testPublishInReconnectingEnqueuesWhenBufferEnabled(): void
    {
        // Arrange
        $writeBuffer = self::createMock(WriteBufferInterface::class);
        $writeBuffer->expects(self::once())->method('enqueue')->willReturn(true);
        $writeBuffer->method('setFailureHandler');

        $client = $this->createClient(
            $writeBuffer,
            new ClientConfiguration(bufferWhileReconnecting: true),
        );
        $this->setState($client, ClientState::RECONNECTING);

        // Act
        $client->publish(new PubMessage('subject', 'payload'));
    }

    public function testDrainDelegatesToWriteBufferDrain(): void
    {
        // Arrange
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('close');

        $writeBuffer = self::createMock(WriteBufferInterface::class);
        $writeBuffer->expects(self::once())->method('drain')->with(1000);
        $writeBuffer->method('setFailureHandler');
        $writeBuffer->method('stop');

        $client = $this->createClient($writeBuffer, connection: $connection);
        $this->setState($client, ClientState::CONNECTED);

        // Act
        $client->drain(1000);

        // Assert
        self::assertSame(ClientState::CLOSED, $client->getState());
    }

    private function createClient(
        WriteBufferInterface $writeBuffer,
        ClientConfiguration|null $configuration = null,
        ConnectionInterface|null $connection = null,
    ): Client {
        $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
        $messageDispatcher->method('dispatch')->willReturn(null);
        $storage = self::createStub(SubscriptionStorageInterface::class);
        $storage->method('all')->willReturn([]);

        $connection ??= self::createStub(ConnectionInterface::class);
        $connection->method('close');

        return new Client(
            configuration: $configuration ?? new ClientConfiguration(
                writeBufferPolicy: WriteBufferPolicy::ERROR,
            ),
            cancellation: new NullCancellation(),
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: $messageDispatcher,
            storage: $storage,
            logger: null,
            writeBuffer: $writeBuffer,
        );
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
