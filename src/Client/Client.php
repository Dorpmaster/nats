<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Domain\Client\ClientConfigurationInterface;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Domain\Event\EventDispatcherInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\SubMessage;
use Dorpmaster\Nats\Protocol\UnSubMessage;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

use function Amp\delay;

final class Client implements ClientInterface
{
    private const string CONNECTED         = 'CONNECTED';
    private const string CONNECTING        = 'CONNECTING';
    private const string DISCONNECTED      = 'DISCONNECTED';
    private const string DISCONNECTING     = 'DISCONNECTING';
    private const string STATUS_EVENT_NAME = 'connectionStatusChanged';

    private string $status;

    // A signal indicating that there are messages being processed.
    private DeferredFuture|null $deferredDispatching = null;

    public function __construct(
        private readonly ClientConfigurationInterface $configuration,
        private readonly Cancellation $cancellation,
        private readonly ConnectionInterface $connection,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageDispatcherInterface $messageDispatcher,
        private readonly SubscriptionStorageInterface $storage,
        private readonly LoggerInterface|null $logger = null,
    ) {
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
            $this->logger?->error('Wrong connection status. Connection process terminated', [
                'status' => $this->status,
            ]);

            throw new ConnectionException(sprintf('Wrong connection status: %s', $status));
        }

        if ($status === self::CONNECTED) {
            $this->logger?->debug('Connection already opened');
            return;
        }

        $this->status = self::CONNECTING;
        $this->logger?->debug('Set status to "CONNECTING"');
        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);

        try {
            $this->connection->open($this->cancellation);
            $this->logger?->debug('Connection has successfully opened');
        } catch (ConnectionException $exception) {
            $this->status = self::DISCONNECTED;
            $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);

            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->status = self::CONNECTED;
        $this->logger?->debug('Set status to "CONNECTED"');
        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);

        $this->logger?->debug('Starting a microtask that processes the messages');
        EventLoop::queue(function () {
            $this->logger?->debug('Starting to processes the messages');
            while ($this->status === self::CONNECTED) {
                $this->logger?->debug('Getting a message');
                try {
                    $message = $this->connection->receive($this->cancellation);
                } catch (CancelledException) {
                    $this->logger?->info('Received a termination signal. Stopping to process the messages.');

                    return;
                }

                /**
                 * Setting the signal to be able to wait for the finish of the dispatched message processing
                 * while the connection is closing
                 */
                $this->deferredDispatching = new DeferredFuture();

                if ($message === null) {
                    $this->logger?->debug('No messages received');
                    $this->deferredDispatching->complete();

                    continue;
                }

                try {
                    $this->logger?->debug('Dispatching the message', [
                        'message' => $message,
                    ]);

                    $response = $this->messageDispatcher->dispatch($message);
                } catch (Throwable $exception) {
                    $this->logger?->error('An exception was thrown during dispatching the message', [
                        'exception' => $exception,
                        'message' => $message,
                    ]);
                    $this->deferredDispatching->complete();

                    continue;
                }

                if ($response !== null) {
                    try {
                        $this->logger?->debug('Sending the response message', [
                            'message' => $response,
                        ]);

                        if ($this->connection->isClosed()) {
                            $this->logger?->error(
                                'Could not send the response message because the connection has already closed'
                            );
                            $this->deferredDispatching->complete();

                            continue;
                        }

                        $this->connection->send($response);

                        $this->logger?->debug('Response message has successfully sent');
                    } catch (Throwable $exception) {
                        $this->logger?->error('An exception was thrown during sending the response message', [
                            'exception' => $exception,
                            'message' => $message,
                        ]);
                    }
                }

                if ($this->deferredDispatching->isComplete() === false) {
                    $this->deferredDispatching->complete();
                }
            }
        });
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
            $this->logger?->error('Wrong connection status. Disconnection process terminated', [
                'status' => $this->status,
            ]);

            throw new ConnectionException(sprintf('Wrong connection status: %s', $status));
        }

        if ($status === self::DISCONNECTED) {
            $this->logger?->debug('Connection already closed');
            return;
        }

        $this->status = self::DISCONNECTING;
        $this->logger?->debug('Set status to "DISCONNECTING"');
        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);

        if ($this->deferredDispatching?->isComplete() === false) {
            $this->logger?->debug('Waiting for the finish of the dispatched message processing');

            try {
                $this->deferredDispatching?->getFuture()
                    ->await(new TimeoutCancellation(10));

                $this->logger?->debug('Dispatched message processing has finished');
            } catch (Throwable $exception) {
                $this->logger?->error(
                    'Time is over while waiting for the finish of the dispatched message processing',
                    [
                        'exception' => $exception,
                    ]
                );
            }
        }

        $this->connection->close();
        $this->status = self::DISCONNECTED;
        $this->logger?->debug('Set status to "DISCONNECTED"');

        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);

        $this->logger?->info('Connection has successfully closed');
    }

    public function waitForTermination(): void
    {
        $suspension = EventLoop::getSuspension();
        $this->cancellation->subscribe(function () use ($suspension): void {
            $this->logger?->debug('Got termination signal. Resuming the process');

            $suspension->resume();
        });

        $this->logger?->debug('Suspending the process.');
        $suspension->suspend();
        // Workaround to give control to EventLoop.
        delay(0.1);
    }

    /**
     * @throws Throwable
     * @throws ConnectionException
     */
    public function subscribe(string $subject, \Closure $closure): string
    {
        $sid     = str_replace('.', '', uniqid(more_entropy: true));
        $message = new SubMessage($subject, $sid);
        $this->storage->add($sid, $closure);

        try {
            $this->connection->send($message);
        } catch (Throwable $exception) {
            $this->logger?->error('An exception was thrown while subscribing', [
                'exception' => $exception,
                'sid' => $sid,
                'subject' => $subject,
            ]);

            $this->storage->remove($sid);

            throw $exception;
        }

        return $sid;
    }

    /**
     * @throws ConnectionException
     * @throws Throwable
     */
    public function unsubscribe(string $sid): void
    {
        $message = new UnSubMessage($sid);
        $this->storage->remove($sid);

        try {
            $this->connection->send($message);
        } catch (Throwable $exception) {
            $this->logger?->error('An exception was thrown while unsubscribing', [
                'exception' => $exception,
                'sid' => $sid,
            ]);

            throw $exception;
        }
    }

    /**
     * @throws Throwable
     * @throws ConnectionException
     */
    public function publish(PubMessageInterface|HPubMessageInterface $message): void
    {
        try {
            $this->connection->send($message);
        } catch (Throwable $exception) {
            $this->logger?->error('An exception was thrown while publishing the message', [
                'exception' => $exception,
                'subject' => $message->getSubject(),
            ]);

            throw $exception;
        }
    }

    public function request(
        PubMessageInterface|HPubMessageInterface $message,
        float $timeout = 30
    ): MsgMessageInterface|HMsgMessageInterface {
        $receiver     = $message->getReplyTo() ?? 'receiver_' . uniqid(more_entropy: true);
        $cancellation = new CompositeCancellation(
            $this->cancellation,
            new TimeoutCancellation($timeout),
        );

        try {
            $deferred = new DeferredFuture();

            $cid = $cancellation->subscribe(static fn(CancelledException $exception) => $deferred->error($exception));
            $sid = $this->subscribe($receiver, static function (MsgMessageInterface|HMsgMessageInterface $message) use ($deferred): null {
                $deferred->complete($message);

                return null;
            });

            $this->publish($message);

            return $deferred->getFuture()->await();
        } catch (Throwable $exception) {
            $this->logger?->error('An exception was thrown while performing the request', [
                'exception' => $exception,
            ]);

            throw $exception;
        } finally {
            if (isset($sid) === true) {
                $this->unsubscribe($sid);
            }

            if (isset($cid) === true) {
                $cancellation->unsubscribe($cid);
            }
        }
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
        $suspension   = EventLoop::getSuspension();

        $cancellationId = $cancellation->subscribe(
            static fn(CancelledException $exception): never => $suspension->throw($exception)
        );

        $logger = $this->logger;

        $subId = $this->eventDispatcher->subscribe(
            self::STATUS_EVENT_NAME,
            static function (
                string $eventName,
                mixed $payload
            ) use (
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

        $status = $suspension->suspend();
        $this->logger?->debug('Got the status', [
            'status' => $status,
        ]);

        $this->eventDispatcher->unsubscribe($subId);

        return $status;
    }
}
