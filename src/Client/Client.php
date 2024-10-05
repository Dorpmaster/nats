<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Domain\Client\ClientConfigurationInterface;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Domain\Event\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class Client implements ClientInterface
{
    private const string CONNECTED = 'CONNECTED';
    private const string CONNECTING = 'CONNECTING';
    private const string DISCONNECTED = 'DISCONNECTED';
    private const string DISCONNECTING = 'DISCONNECTING';
    private const string STATUS_EVENT_NAME = 'connectionStatusChanged';

    private string $status;

    public function __construct(
        private readonly ClientConfigurationInterface $configuration,
        private readonly Cancellation $cancellation,
        private readonly ConnectionInterface $connection,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface|null $logger = null,
    )
    {
        $this->status = self::DISCONNECTED;
    }

    public function connect(): void
    {
        $this->logger?->debug('Opening a new connection');

        try {
            $status = match ($this->status) {
                self::CONNECTED => self::CONNECTED,
                self::CONNECTING => $this->waitForStatus(
                    self::CONNECTED,
                    $this->configuration->getWaitForStatusTimeout(),
                ),
                self::DISCONNECTING => $this->waitForStatus(
                    self::DISCONNECTED,
                    $this->configuration->getWaitForStatusTimeout(),
                ),
                self::DISCONNECTED => self::DISCONNECTED,
                default => null,
            };
        } catch (CancelledException $exception) {
            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger?->debug(sprintf('Status of the connection: %s', $status));

        if (!in_array($status, [self::CONNECTED, self::DISCONNECTED])) {
            $this->logger?->error('Wrong connection status. Connection process terminated',[
                'status' => $this->status,
            ]);

            throw new ConnectionException(sprintf('Wrong connection status: %s', $status));
        }

        if ($status === self::CONNECTED) {
            $this->logger?->debug('Connection already opened');
            return;
        }

        $this->status = self::CONNECTING;
        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);
        $this->logger?->debug('Set status to "CONNECTING"');

        try {
            $this->connection->open($this->cancellation);
            $this->logger?->debug('Connection has successfully opened');
        } catch (ConnectionException $exception) {
            $this->status = self::DISCONNECTED;
            $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);

            $this->logger?->error($exception->getMessage(),[
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->status = self::CONNECTED;
        $this->logger?->debug('Set status to "CONNECTED"');
        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);
    }

    public function disconnect(): void
    {
        $this->logger?->debug('Closing the connection');

        try {
            $status = match ($this->status) {
                self::CONNECTED => self::CONNECTED,
                self::CONNECTING => $this->waitForStatus(self::CONNECTED),
                self::DISCONNECTING => $this->waitForStatus(self::DISCONNECTED),
                self::DISCONNECTED => self::DISCONNECTED,
                default => null,
            };
        } catch (CancelledException $exception) {
            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger?->debug(sprintf('Status of the connection: %s', $status));

        if (!in_array($status, [self::CONNECTED, self::DISCONNECTED])) {
            $this->logger?->error('Wrong connection status. Disconnection process terminated',[
                'status' => $this->status,
            ]);

            throw new ConnectionException(sprintf('Wrong connection status: %s', $status));
        }

        if ($status === self::DISCONNECTED) {
            $this->logger?->debug('Connection already closed');
            return;
        }

        $this->status = self::DISCONNECTING;
        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);
        $this->logger?->debug('Set status to "DISCONNECTING"');

        $this->connection->close();
        $this->logger?->debug('Connection has successfully closed');
        $this->status = self::DISCONNECTED;
        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);
    }


    /**
     * @throws CancelledException
     */
    private function waitForStatus(string $status, float $timeout = 10): string
    {
        $this->logger?->debug('Waiting for the connection status', [
            'status' => $status,
            'timeout' => $timeout,
        ]);

        $cancellation = new TimeoutCancellation(
            $timeout,
            sprintf('Operation timed out while waiting for the connection status "%s"', $status),
        );
        $suspension = EventLoop::getSuspension();

        $cancellationId = $cancellation->subscribe(
            static fn(CancelledException $exception): never => $suspension->throw($exception)
        );

        $logger = $this->logger;
        $this->eventDispatcher->subscribe(
            self::STATUS_EVENT_NAME,
            static function (string $eventName, mixed $payload) use (
                $suspension,
                $status,
                $cancellationId,
                $cancellation,
                $logger,
            ): void {
                $logger?->debug('Catch the event', [
                    'event' => $eventName,
                    'payload' => $payload,
                ]);

                if ($eventName !== self::STATUS_EVENT_NAME) {
                    return;
                }

                if ($status !== $payload) {
                    return;
                }

                $cancellation->unsubscribe($cancellationId);
                $suspension->resume($payload);
            }
        );

        return $suspension->suspend();
    }
}
