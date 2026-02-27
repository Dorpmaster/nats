<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Domain\Client\ClientConfigurationInterface;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Domain\Event\EventDispatcherInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Protocol\SubMessage;
use Dorpmaster\Nats\Protocol\UnSubMessage;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

use function Amp\delay;

final class Client implements ClientInterface
{
    private const string NEW               = 'NEW';
    private const string CONNECTING        = 'CONNECTING';
    private const string CONNECTED         = 'CONNECTED';
    private const string RECONNECTING      = 'RECONNECTING';
    private const string DRAINING          = 'DRAINING';
    private const string CLOSED            = 'CLOSED';
    private const string STATUS_EVENT_NAME = 'connectionStatusChanged';

    private string $status;

    // A signal indicating that there are messages being processed.
    private DeferredFuture|null $deferredDispatching = null;
    /** @var array<string, string> */
    private array $subscriptionsBySid = [];
    private readonly SubscriptionIdHelperInterface $subscriptionIdHelper;

    public function __construct(
        private readonly ClientConfigurationInterface $configuration,
        private readonly Cancellation $cancellation,
        private readonly ConnectionInterface $connection,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageDispatcherInterface $messageDispatcher,
        private readonly SubscriptionStorageInterface $storage,
        private readonly LoggerInterface|null $logger = null,
        private readonly DelayStrategyInterface|null $delayStrategy = null,
        SubscriptionIdHelperInterface|null $subscriptionIdHelper = null,
    ) {
        $this->status = self::NEW;
        $this->subscriptionIdHelper = $subscriptionIdHelper ?? new SubscriptionIdHelper();
    }

    public function connect(): void
    {
        $this->logger?->debug('Opening a new connection');

        try {
            $status = match ($this->status) {
                self::CONNECTED => self::CONNECTED,
                self::CONNECTING, self::RECONNECTING => $this->waitForStatus(
                    self::CONNECTED,
                    $this->configuration->getWaitForStatusTimeout(),
                ),
                self::DRAINING => $this->waitForStatus(
                    self::CLOSED,
                    $this->configuration->getWaitForStatusTimeout(),
                ),
                self::NEW, self::CLOSED => $this->status,
                default => null,
            };
        } catch (CancelledException $exception) {
            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger?->debug(sprintf('Status of the connection: %s', $status));

        if (!in_array($status, [self::CONNECTED, self::NEW, self::CLOSED], true)) {
            $this->logger?->error('Wrong connection status. Connection process terminated', [
                'status' => $this->status,
            ]);

            throw new ConnectionException(sprintf('Wrong connection status: %s', $status));
        }

        if ($status === self::CONNECTED) {
            $this->logger?->debug('Connection already opened');
            return;
        }

        $this->transitionTo(self::CONNECTING, 'connect() started');

        try {
            $this->connection->open($this->cancellation);
            $this->logger?->debug('Connection has successfully opened');
        } catch (ConnectionException $exception) {
            $this->transitionTo(self::CLOSED, 'open() failed');

            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->transitionTo(self::CONNECTED, 'open() succeeded');

        $this->logger?->debug('Starting a microtask that processes the messages');
        EventLoop::queue(function () {
            $pendingResubscribe = false;

            $this->logger?->debug('Starting to processes the messages');
            while (in_array($this->status, [self::CONNECTED, self::RECONNECTING], true)) {
                $this->logger?->debug('Getting a message');
                try {
                    $message = $this->connection->receive($this->cancellation);
                } catch (CancelledException) {
                    $this->logger?->info('Received a termination signal. Stopping to process the messages.');

                    return;
                } catch (Throwable $exception) {
                    if (!$this->tryReconnect($exception)) {
                        return;
                    }

                    $pendingResubscribe = true;

                    continue;
                }

                /**
                 * Setting the signal to be able to wait for the finish of the dispatched message processing
                 * while the connection is closing
                 */
                $this->deferredDispatching = new DeferredFuture();

                if ($message === null) {
                    $this->logger?->debug('No messages received');
                    $this->deferredDispatching->complete();

                    if ($this->connection->isClosed()) {
                        if (!$this->tryReconnect()) {
                            return;
                        }

                        $pendingResubscribe = true;
                    }

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

                if ($pendingResubscribe && $message->getType() === NatsMessageType::INFO) {
                    $this->restoreSubscriptions();
                    $pendingResubscribe = false;
                }

                if ($this->deferredDispatching->isComplete() === false) {
                    $this->deferredDispatching->complete();
                }
            }
        });
    }

    public function disconnect(): void
    {
        $this->drain();
    }

    public function drain(int|null $timeoutMs = null): void
    {
        $this->logger?->debug('Draining the connection');

        try {
            $status = match ($this->status) {
                self::CONNECTED => self::CONNECTED,
                self::CONNECTING => $this->waitForStatus(self::CONNECTED),
                self::RECONNECTING => self::RECONNECTING,
                self::DRAINING => self::DRAINING,
                self::NEW, self::CLOSED => self::CLOSED,
                default => null,
            };
        } catch (CancelledException $exception) {
            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger?->debug(sprintf('Status of the connection: %s', $status));

        if (!in_array($status, [self::CONNECTED, self::RECONNECTING, self::DRAINING, self::CLOSED], true)) {
            $this->logger?->error('Wrong connection status. Disconnection process terminated', [
                'status' => $this->status,
            ]);

            throw new ConnectionException(sprintf('Wrong connection status: %s', $status));
        }

        if ($status === self::CLOSED) {
            $this->logger?->debug('Connection already closed');
            return;
        }

        if ($this->status !== self::DRAINING) {
            $this->transitionTo(self::DRAINING, 'drain() started');
        }

        if ($this->deferredDispatching?->isComplete() === false) {
            $this->logger?->debug('Waiting for the finish of the dispatched message processing');

            try {
                $timeout = ($timeoutMs ?? 10_000) / 1000;
                $this->deferredDispatching?->getFuture()
                    ->await(new TimeoutCancellation($timeout));

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

        $this->unsubscribeAll();
        $this->connection->close();
        $this->transitionTo(self::CLOSED, 'drain() finished');

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
        $sid     = $this->subscriptionIdHelper->generateId();
        $message = new SubMessage($subject, $sid);
        $this->logger?->debug('Subscribing', [
            'subject' => $subject,
            'sid' => $sid,
        ]);

        $this->storage->add($sid, $closure);
        $this->subscriptionsBySid[$sid] = $subject;
        $this->logger?->debug('Subscription saved to storage');

        try {
            $this->logger?->debug('Sending "SUB" message', [
                'message' => $message,
            ]);
            $this->connection->send($message);
        } catch (Throwable $exception) {
            $this->logger?->error('An exception was thrown while subscribing', [
                'exception' => $exception,
                'sid' => $sid,
                'subject' => $subject,
            ]);

            $this->storage->remove($sid);
            unset($this->subscriptionsBySid[$sid]);

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
        unset($this->subscriptionsBySid[$sid]);

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
        if (in_array($this->status, [self::DRAINING, self::CLOSED], true)) {
            throw new ConnectionException(sprintf('Could not publish while client state is %s', $this->status));
        }

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
        if (in_array($this->status, [self::DRAINING, self::CLOSED], true)) {
            throw new ConnectionException(sprintf('Could not request while client state is %s', $this->status));
        }

        $id             = $this->subscriptionIdHelper->generateId();
        $receiver       = $message->getReplyTo();
        $requestMessage = $message;
        if ($receiver === null) {
            $receiver       = 'receiver' . $id;
            $requestMessage = match ($message->getType()) {
                NatsMessageType::PUB => new PubMessage($message->getSubject(), $message->getPayload(), $receiver),
                NatsMessageType::HPUB => new HPubMessage(
                    $message->getSubject(),
                    $message->getPayload(),
                    $message->getHeaders(),
                    $receiver,
                ),
            };
        }
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

            $this->publish($requestMessage);

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

    private function tryReconnect(Throwable|null $reason = null): bool
    {
        if (!$this->configuration->isReconnectEnabled()) {
            $this->logger?->warning('Reconnect is disabled. Stopping message processing.', [
                'exception' => $reason,
            ]);

            $this->transitionTo(self::CLOSED, 'reconnect disabled');

            return false;
        }

        if (!in_array($this->status, [self::CONNECTED, self::RECONNECTING], true)) {
            return false;
        }

        $this->transitionTo(self::RECONNECTING, 'reconnect started');

        $maxAttempts = $this->configuration->getMaxReconnectAttempts();
        $attempt     = 0;

        while (true) {
            if ($maxAttempts !== null && $attempt >= $maxAttempts) {
                $this->logger?->error('Reconnect attempts are exhausted', [
                    'attempts' => $attempt,
                    'exception' => $reason,
                ]);

                $this->transitionTo(self::CLOSED, 'reconnect attempts exhausted');

                return false;
            }

            $attempt++;
            try {
                $this->connection->close();
                $this->connection->open($this->cancellation);
                if ($this->connection->isClosed()) {
                    throw new ConnectionException('Reconnect open() returned a closed connection');
                }
                $this->transitionTo(self::CONNECTED, 'reconnect succeeded');

                return true;
            } catch (Throwable $exception) {
                $this->logger?->warning('Reconnect attempt failed', [
                    'attempt' => $attempt,
                    'exception' => $exception,
                ]);
            }

            $this->waitReconnectBackoff($attempt);
            if ($this->status !== self::RECONNECTING) {
                return false;
            }
        }
    }

    private function waitReconnectBackoff(int $attempt): void
    {
        $delayMs = $this->calculateReconnectDelayMs($attempt);

        try {
            if ($this->delayStrategy !== null) {
                $this->delayStrategy->delay((int) round($delayMs));
            } else {
                (new EventLoopDelayStrategy())->delay((int) round($delayMs));
            }
        } catch (CancelledException) {
            $this->transitionTo(self::CLOSED, 'reconnect delay cancelled');
        }
    }

    private function calculateReconnectDelayMs(int $attempt): float
    {
        $initial = (float) $this->configuration->getReconnectBackoffInitialMs();
        $max     = (float) $this->configuration->getReconnectBackoffMaxMs();
        $factor  = max(1.0, $this->configuration->getReconnectBackoffMultiplier());

        $baseDelay = min($max, $initial * ($factor ** max(0, $attempt - 1)));
        $jitterMax = max(0.0, $this->configuration->getReconnectJitterFraction()) * $baseDelay;
        if ($jitterMax <= 0.0) {
            return $baseDelay;
        }

        return $baseDelay + (lcg_value() * $jitterMax);
    }

    private function restoreSubscriptions(): void
    {
        foreach ($this->subscriptionsBySid as $sid => $subject) {
            $this->connection->send(new SubMessage($subject, $sid));
        }
    }

    private function unsubscribeAll(): void
    {
        foreach (array_keys($this->subscriptionsBySid) as $sid) {
            $this->unsubscribe($sid);
        }
    }

    private function transitionTo(string $targetState, string $reason = ''): void
    {
        if ($this->status === $targetState) {
            return;
        }

        $allowedTransitions = [
            self::NEW => [self::CONNECTING, self::CLOSED],
            self::CONNECTING => [self::CONNECTED, self::RECONNECTING, self::DRAINING, self::CLOSED],
            self::CONNECTED => [self::RECONNECTING, self::DRAINING, self::CLOSED],
            self::RECONNECTING => [self::CONNECTED, self::DRAINING, self::CLOSED],
            self::DRAINING => [self::CLOSED],
            self::CLOSED => [self::CONNECTING],
        ];

        $allowed = $allowedTransitions[$this->status] ?? [];
        if (!in_array($targetState, $allowed, true)) {
            throw new \LogicException(sprintf(
                'Illegal state transition: %s -> %s',
                $this->status,
                $targetState,
            ));
        }

        $previous    = $this->status;
        $this->status = $targetState;
        $this->logger?->debug('Client state transition', [
            'from' => $previous,
            'to' => $this->status,
            'reason' => $reason,
        ]);
        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $this->status);
    }

    public function getState(): string
    {
        return $this->status;
    }
}
