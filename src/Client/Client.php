<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Closure;
use Dorpmaster\Nats\Domain\Client\ClientConfigurationInterface;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\InboundDispatchOverflowException;
use Dorpmaster\Nats\Domain\Client\ClientNotConnectedException;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\PingServiceInterface;
use Dorpmaster\Nats\Domain\Client\ReconnectBackoffServiceInterface;
use Dorpmaster\Nats\Domain\Client\ReconnectDelayHelperInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferOverflowException;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Connection\ServerPoolInterface;
use Dorpmaster\Nats\Domain\Connection\ServerSelectableConnectionInterface;
use Dorpmaster\Nats\Domain\Event\EventDispatcherInterface;
use Dorpmaster\Nats\Domain\Telemetry\MetricsCollectorInterface;
use Dorpmaster\Nats\Connection\ServerPoolService;
use Dorpmaster\Nats\Client\Cluster\InfoConnectUrlsExtractor;
use Dorpmaster\Nats\Protocol\Contracts\ConnectMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\OutboundFrameBuilder;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Protocol\SubMessage;
use Dorpmaster\Nats\Protocol\UnSubMessage;
use LogicException;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

use function Amp\delay;

final class Client implements ClientInterface
{
    private const string STATUS_EVENT_NAME = 'connectionStatusChanged';

    private ClientState $status;

    private DeferredCancellation|null $reconnectBackoffCancellation = null;
    private int $reconnectBackoffEpoch                              = 0;
    private int|null $activeReconnectBackoffEpoch                   = null;
    private bool $handshakeReady                                    = false;
    private bool $connectedEventPending                             = false;
    private bool $awaitingInitialPong                               = false;
    private bool $replaySubscriptionsOnReady                        = false;
    /** @var array<string, string> */
    private array $subscriptionsBySid = [];
    private readonly SubscriptionIdHelperInterface $subscriptionIdHelper;
    private readonly ReconnectBackoffServiceInterface $reconnectBackoffService;
    private readonly WriteBufferInterface $writeBuffer;
    private readonly OutboundFrameBuilder $outboundFrameBuilder;
    private readonly MetricsCollectorInterface $metricsCollector;
    private readonly PingServiceInterface $pingService;
    private readonly ServerPoolInterface $serverPool;
    private readonly InfoConnectUrlsExtractor $infoConnectUrlsExtractor;
    private readonly InboundDispatchScheduler $inboundDispatchScheduler;

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
        ReconnectDelayHelperInterface|null $reconnectDelayHelper = null,
        ReconnectBackoffServiceInterface|null $reconnectBackoffService = null,
        WriteBufferInterface|null $writeBuffer = null,
        OutboundFrameBuilder|null $outboundFrameBuilder = null,
        PingServiceInterface|null $pingService = null,
        ServerPoolInterface|null $serverPool = null,
        InfoConnectUrlsExtractor|null $infoConnectUrlsExtractor = null,
    ) {
        $this->status                  = ClientState::NEW;
        $this->metricsCollector        = $this->configuration->getMetricsCollector();
        $this->subscriptionIdHelper    = $subscriptionIdHelper ?? new SubscriptionIdHelper();
        $this->reconnectBackoffService = $reconnectBackoffService
            ?? new ReconnectBackoffService(
                $this->delayStrategy ?? new EventLoopDelayStrategy(),
                $reconnectDelayHelper ?? new ReconnectDelayHelper(),
            );
        $this->writeBuffer             = $writeBuffer ?? new WriteBufferService(
            $this->configuration->getMaxWriteBufferMessages(),
            $this->configuration->getMaxWriteBufferBytes(),
            $this->configuration->getWriteBufferPolicy(),
            $this->logger,
            $this->metricsCollector,
        );
        $this->writeBuffer->setFailureHandler(function (Throwable $exception): void {
            if ($this->status === ClientState::CONNECTED) {
                $this->tryReconnect($exception);
            }
        });
        $this->outboundFrameBuilder     = $outboundFrameBuilder ?? new OutboundFrameBuilder();
        $this->pingService              = $pingService ?? new PingService(
            $this->delayStrategy ?? new EventLoopDelayStrategy(),
            $this->configuration->getPingIntervalMs(),
            $this->configuration->getPingTimeoutMs(),
            $this->metricsCollector,
            $this->configuration->getTimeProvider(),
        );
        $this->serverPool               = $serverPool ?? new ServerPoolService($this->configuration->getTimeProvider());
        $this->infoConnectUrlsExtractor = $infoConnectUrlsExtractor ?? new InfoConnectUrlsExtractor();
        $this->inboundDispatchScheduler = new InboundDispatchScheduler(
            $this->configuration->getMaxInboundDispatchConcurrency(),
            $this->configuration->getMaxPendingInboundDispatch(),
            $this->logger,
        );
        $servers                        = $this->configuration->getServers();
        if ($servers !== []) {
            $this->serverPool->addServers($servers);
        }
    }

    public function connect(): void
    {
        $this->logger?->debug('Opening a new connection');

        try {
            $status = match ($this->status) {
                ClientState::CONNECTED => ClientState::CONNECTED,
                ClientState::CONNECTING, ClientState::RECONNECTING => $this->waitForStatus(
                    ClientState::CONNECTED,
                    $this->configuration->getWaitForStatusTimeout(),
                ),
                ClientState::DRAINING => $this->waitForStatus(
                    ClientState::CLOSED,
                    $this->configuration->getWaitForStatusTimeout(),
                ),
                ClientState::NEW, ClientState::CLOSED => $this->status,
                default => null,
            };
        } catch (CancelledException $exception) {
            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger?->debug(sprintf('Status of the connection: %s', $status->value));

        if (!in_array($status, [ClientState::CONNECTED, ClientState::NEW, ClientState::CLOSED], true)) {
            $this->logger?->error('Wrong connection status. Connection process terminated', [
                'status' => $this->status->value,
            ]);

            throw new ConnectionException(sprintf('Wrong connection status: %s', $status->value));
        }

        if ($status === ClientState::CONNECTED) {
            $this->logger?->debug('Connection already opened');
            return;
        }

        $this->moveToState(ClientState::CONNECTING, 'connect() started');

        $server = $this->selectServerForConnect();

        try {
            $this->openConnectionForServer($server);
            if ($server !== null) {
                $this->serverPool->setCurrent($server);
            }
            $this->logger?->debug('Connection has successfully opened');
        } catch (ConnectionException $exception) {
            $this->moveToState(ClientState::CLOSED, 'open() failed');

            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->moveToState(ClientState::CONNECTED, 'open() succeeded');

        $this->logger?->debug('Starting a microtask that processes the messages');
        EventLoop::queue(function () {
            $this->logger?->debug('Starting to processes the messages');
            while (in_array($this->status, [ClientState::CONNECTED, ClientState::RECONNECTING], true)) {
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

                    continue;
                }

                if ($message === null) {
                    $this->logger?->debug('No messages received');

                    if ($this->connection->isClosed()) {
                        if (!$this->tryReconnect()) {
                            return;
                        }
                    }

                    continue;
                }

                if ($message->getType() === NatsMessageType::PONG) {
                    if ($this->awaitingInitialPong && !$this->handshakeReady) {
                        $this->completeHandshake();
                    }

                    $this->pingService->onPongReceived();
                }

                if ($message instanceof InfoMessageInterface) {
                    $this->discoverServersFromInfo($message);
                }

                try {
                    $this->logger?->debug('Dispatching the message', [
                        'message' => $message,
                    ]);

                    if (in_array($message->getType(), [NatsMessageType::MSG, NatsMessageType::HMSG], true)) {
                        assert($message instanceof MsgMessageInterface || $message instanceof HMsgMessageInterface);
                        $this->inboundDispatchScheduler->dispatch($message, function () use ($message): void {
                            $response = $this->messageDispatcher->dispatch($message);
                            if (
                                !$response instanceof PubMessageInterface
                                && !$response instanceof HPubMessageInterface
                            ) {
                                return;
                            }

                            assert($response instanceof NatsProtocolMessageInterface);

                            $this->logger?->debug('Queueing the response message', [
                                'message' => $response,
                            ]);

                            $this->enqueueInboundDispatchResponse($response);
                        });

                        continue;
                    }

                    $response = $this->messageDispatcher->dispatch($message);
                } catch (InboundDispatchOverflowException $exception) {
                    if (!$this->tryReconnect($exception)) {
                        return;
                    }

                    continue;
                } catch (Throwable $exception) {
                    $this->logger?->error('An exception was thrown during dispatching the message', [
                        'exception' => $exception,
                        'message' => $message,
                    ]);

                    continue;
                }

                if ($response !== null) {
                    try {
                        $this->logger?->debug('Queueing the response message', [
                            'message' => $response,
                        ]);

                        $this->sendResponseMessage($response);
                    } catch (Throwable $exception) {
                        $this->logger?->error('An exception was thrown during sending the response message', [
                            'exception' => $exception,
                            'message' => $message,
                        ]);
                    }
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
                ClientState::CONNECTED => ClientState::CONNECTED,
                ClientState::CONNECTING => $this->waitForStatus(ClientState::CONNECTED),
                ClientState::RECONNECTING => ClientState::RECONNECTING,
                ClientState::DRAINING => ClientState::DRAINING,
                ClientState::NEW, ClientState::CLOSED => ClientState::CLOSED,
                default => null,
            };
        } catch (CancelledException $exception) {
            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger?->debug(sprintf('Status of the connection: %s', $status->value));

        if (!in_array($status, [ClientState::CONNECTED, ClientState::RECONNECTING, ClientState::DRAINING, ClientState::CLOSED], true)) {
            $this->logger?->error('Wrong connection status. Disconnection process terminated', [
                'status' => $this->status->value,
            ]);

            throw new ConnectionException(sprintf('Wrong connection status: %s', $status->value));
        }

        if ($status === ClientState::CLOSED) {
            $this->logger?->debug('Connection already closed');
            return;
        }

        if ($this->status !== ClientState::DRAINING) {
            $this->moveToState(ClientState::DRAINING, 'drain() started');
        }

        $this->inboundDispatchScheduler->stop();
        $this->inboundDispatchScheduler->drain($timeoutMs);

        $this->writeBuffer->drain($timeoutMs);

        $this->unsubscribeAll();
        $this->connection->close();
        $this->moveToState(ClientState::CLOSED, 'drain() finished');

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
    public function subscribe(string $subject, Closure $closure): string
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
            $this->enqueueOutbound($message);
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
            if ($this->status === ClientState::DRAINING && !$this->connection->isClosed()) {
                $this->sendImmediate($message);

                return;
            }

            if ($this->status === ClientState::CLOSED) {
                return;
            }

            if ($this->status === ClientState::RECONNECTING) {
                return;
            }

            $this->enqueueOutbound($message);
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
        assert($message instanceof NatsProtocolMessageInterface);

        $this->enqueueOutbound($message);
    }

    public function request(
        PubMessageInterface|HPubMessageInterface $message,
        float $timeout = 30
    ): MsgMessageInterface|HMsgMessageInterface {
        if (in_array($this->status, [ClientState::DRAINING, ClientState::CLOSED], true)) {
            throw new ConnectionException(sprintf('Could not request while client state is %s', $this->status->value));
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
        assert($requestMessage instanceof NatsProtocolMessageInterface);

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
                try {
                    $this->unsubscribe($sid);
                } catch (Throwable $exception) {
                    $this->logger?->debug('Ignoring request cleanup unsubscribe failure', [
                        'sid' => $sid,
                        'exception' => $exception,
                    ]);
                }
            }

            if (isset($cid) === true) {
                $cancellation->unsubscribe($cid);
            }
        }
    }

    /**
     * @throws CancelledException
     */
    private function waitForStatus(ClientState $status, float $timeout = 10): ClientState
    {
        if ($this->isStateObservable($status)) {
            return $this->status;
        }

        $this->logger?->debug('Waiting for the connection status', [
            'status' => $status->value,
            'timeout' => $timeout,
        ]);

        $cancellation = new TimeoutCancellation(
            $timeout,
            sprintf('Operation timed out while waiting for the connection status "%s"', $status->value),
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

                if (!$payload instanceof ClientState) {
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
        if (!$status instanceof ClientState) {
            throw new LogicException('Connection status event payload must be an instance of ClientState');
        }

        $this->logger?->debug('Got the status', [
            'status' => $status->value,
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

            $this->moveToState(ClientState::CLOSED, 'reconnect disabled');

            return false;
        }

        if (!in_array($this->status, [ClientState::CONNECTED, ClientState::RECONNECTING], true)) {
            return false;
        }

        $this->moveToState(ClientState::RECONNECTING, 'reconnect started');

        $maxAttempts        = $this->configuration->getMaxReconnectAttempts();
        $attempt            = 0; // Count only real open() attempts.
        $triedCurrentServer = false;

        while (true) {
            if ($maxAttempts !== null && $attempt >= $maxAttempts) {
                $this->logger?->error('Reconnect attempts are exhausted', [
                    'attempts' => $attempt,
                    'exception' => $reason,
                ]);

                $this->moveToState(ClientState::CLOSED, 'reconnect attempts exhausted');

                return false;
            }

            $server          = $this->selectServerForReconnect($triedCurrentServer);
            $hasKnownServers = $this->serverPool->allServers() !== [];
            if ($server === null && $hasKnownServers) {
                if (!$this->waitReconnectBackoff(max(1, $attempt + 1), $this->activeReconnectBackoffEpoch)) {
                    return false;
                }

                continue;
            }

            $attempt++;
            try {
                $this->connection->close();
                $this->openConnectionForServer($server);
                if ($this->connection->isClosed()) {
                    throw new ConnectionException('Reconnect open() returned a closed connection');
                }
                if ($server !== null) {
                    $this->serverPool->setCurrent($server);
                }
                $this->moveToState(ClientState::CONNECTED, 'reconnect succeeded');
                $this->replaySubscriptionsOnReady = true;

                return true;
            } catch (Throwable $exception) {
                if ($server !== null) {
                    $this->serverPool->markDead($server, $this->configuration->getDeadServerCooldownMs());
                }
                $this->logger?->warning('Reconnect attempt failed', [
                    'attempt' => $attempt,
                    'server' => $server?->toUri(),
                    'exception' => $exception,
                ]);
            }

            if (!$this->waitReconnectBackoff($attempt, $this->activeReconnectBackoffEpoch)) {
                return false;
            }
        }
    }

    private function selectServerForConnect(): ServerAddress|null
    {
        return $this->serverPool->nextServer();
    }

    private function selectServerForReconnect(bool &$triedCurrentServer): ServerAddress|null
    {
        $current = $this->serverPool->getCurrent();
        if (!$triedCurrentServer && $current !== null) {
            $triedCurrentServer = true;

            return $current;
        }

        $candidate = $this->serverPool->nextServer();
        if ($candidate === null) {
            return null;
        }

        if ($current === null || !$candidate->equals($current)) {
            return $candidate;
        }

        $knownServers = $this->serverPool->allServers();
        $maxRolls     = max(0, count($knownServers) - 1);
        for ($roll = 0; $roll < $maxRolls; $roll++) {
            $next = $this->serverPool->nextServer();
            if ($next === null) {
                return null;
            }

            if (!$next->equals($current)) {
                return $next;
            }
        }

        return null;
    }

    /**
     * @throws ConnectionException
     */
    private function openConnectionForServer(ServerAddress|null $server): void
    {
        if ($server !== null && $this->connection instanceof ServerSelectableConnectionInterface) {
            $this->connection->useServer($server);
        }

        $this->connection->open($this->cancellation);
    }

    private function waitReconnectBackoff(int $attempt, int|null $epoch): bool
    {
        if ($epoch === null || !$this->isReconnectBackoffContextActive($epoch)) {
            return false;
        }

        try {
            $this->reconnectBackoffService->wait(
                $attempt,
                $this->configuration,
                $this->reconnectBackoffCancellation?->getCancellation(),
            );
        } catch (CancelledException) {
            $this->metricsCollector->increment('reconnect_backoff_cancelled', 1, [
                'reason' => 'lifecycle',
            ]);
            if ($this->isReconnectBackoffContextActive($epoch)) {
                $this->logger?->debug('Reconnect backoff cancelled while reconnect context is still active');
            }

            return false;
        }

        return $this->isReconnectBackoffContextActive($epoch);
    }

    private function unsubscribeAll(): void
    {
        foreach (array_keys($this->subscriptionsBySid) as $sid) {
            try {
                $this->unsubscribe($sid);
            } catch (Throwable $exception) {
                $this->logger?->debug('Ignoring unsubscribeAll failure during shutdown', [
                    'sid' => $sid,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function moveToState(ClientState $targetState, string $reason = ''): void
    {
        $previous = $this->transitionTo($targetState, $reason);
        if ($previous === null) {
            return;
        }

        $this->applyTransitionEffects($previous, $targetState);
        $this->dispatchTransitionEvent($targetState);
    }

    private function transitionTo(ClientState $targetState, string $reason = ''): ClientState|null
    {
        if ($this->status === $targetState) {
            return null;
        }

        $allowedTransitions = [
            ClientState::NEW->value => [ClientState::CONNECTING, ClientState::CLOSED],
            ClientState::CONNECTING->value => [ClientState::CONNECTED, ClientState::RECONNECTING, ClientState::DRAINING, ClientState::CLOSED],
            ClientState::CONNECTED->value => [ClientState::RECONNECTING, ClientState::DRAINING, ClientState::CLOSED],
            ClientState::RECONNECTING->value => [ClientState::CONNECTED, ClientState::DRAINING, ClientState::CLOSED],
            ClientState::DRAINING->value => [ClientState::CLOSED],
            ClientState::CLOSED->value => [ClientState::CONNECTING],
        ];

        $allowed = $allowedTransitions[$this->status->value] ?? [];
        if (!in_array($targetState, $allowed, true)) {
            throw new LogicException(sprintf(
                'Illegal state transition: %s -> %s',
                $this->status->value,
                $targetState->value,
            ));
        }

        $previous     = $this->status;
        $this->status = $targetState;
        $this->logger?->debug('Client state transition', [
            'from' => $previous->value,
            'to' => $this->status->value,
            'reason' => $reason,
        ]);

        return $previous;
    }

    private function applyTransitionEffects(ClientState $from, ClientState $to): void
    {
        if ($to !== ClientState::CONNECTED) {
            $this->connectedEventPending = false;
        }

        if ($from === ClientState::CONNECTED && $to !== ClientState::CONNECTED) {
            $this->pingService->stop();
        }

        if ($from === ClientState::RECONNECTING && $to !== ClientState::RECONNECTING) {
            $this->stopReconnectBackoffContext();
        }

        match ($to) {
            ClientState::CONNECTED => $this->applyConnectedTransitionEffects($from),
            ClientState::RECONNECTING => $this->applyReconnectingTransitionEffects(),
            ClientState::DRAINING => $this->applyDrainingTransitionEffects(),
            ClientState::CLOSED => $this->applyClosedTransitionEffects(),
            default => null,
        };
    }

    private function applyConnectedTransitionEffects(ClientState $from): void
    {
        $this->inboundDispatchScheduler->reset();
        $this->handshakeReady        = false;
        $this->connectedEventPending = true;
        $this->awaitingInitialPong   = false;
        if ($from !== ClientState::RECONNECTING) {
            $this->replaySubscriptionsOnReady = false;
        }

        $this->writeBuffer->start($this->connection);
        $this->writeBuffer->pause();
        if ($from === ClientState::RECONNECTING) {
            $this->metricsCollector->increment('reconnect_count', 1);
        }
    }

    private function applyReconnectingTransitionEffects(): void
    {
        $this->startReconnectBackoffContext();
        $this->inboundDispatchScheduler->stop();
        if ($this->configuration->isBufferWhileReconnecting()) {
            $this->writeBuffer->detach();

            return;
        }

        $this->writeBuffer->stop();
    }

    private function applyDrainingTransitionEffects(): void
    {
        $this->inboundDispatchScheduler->stop();
    }

    private function applyClosedTransitionEffects(): void
    {
        $this->inboundDispatchScheduler->stop();
        $this->writeBuffer->stop();
    }

    private function dispatchTransitionEvent(ClientState $state): void
    {
        if ($state === ClientState::CONNECTED) {
            return;
        }

        $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, $state);
    }

    private function startReconnectBackoffContext(): void
    {
        $this->reconnectBackoffCancellation?->cancel();
        $this->reconnectBackoffCancellation = new DeferredCancellation();
        $this->reconnectBackoffEpoch++;
        $this->activeReconnectBackoffEpoch = $this->reconnectBackoffEpoch;
    }

    private function stopReconnectBackoffContext(): void
    {
        $this->reconnectBackoffCancellation?->cancel();
        $this->reconnectBackoffCancellation = null;
        $this->activeReconnectBackoffEpoch  = null;
    }

    private function enqueueOutbound(NatsProtocolMessageInterface $message, bool $allowBufferWhileReconnecting = false): void
    {
        $state = $this->assertCanEnqueueOutbound($allowBufferWhileReconnecting);

        $this->enqueueFrame($message, $state);
    }

    private function sendResponseMessage(NatsProtocolMessageInterface $message): void
    {
        if ($message instanceof ConnectMessageInterface) {
            $this->sendImmediate($message);
            $this->awaitingInitialPong = true;
            $this->sendImmediate(new PingMessage());

            return;
        }

        if (!$this->handshakeReady && $message->getType() === NatsMessageType::PONG) {
            $this->sendImmediate($message);

            return;
        }

        $this->enqueueOutbound($message, true);
    }

    private function sendImmediate(NatsProtocolMessageInterface $message): void
    {
        $this->connection->send($message);
    }

    private function completeHandshake(): void
    {
        if ($this->handshakeReady) {
            return;
        }

        $this->awaitingInitialPong = false;
        $this->handshakeReady      = true;
        if ($this->replaySubscriptionsOnReady) {
            $this->replaySubscriptionsOnReady = false;
            $this->restoreSubscriptionsImmediately();
        }
        $this->writeBuffer->resume();

        if ($this->configuration->isPingEnabled()) {
            $this->pingService->start(
                function (): void {
                    try {
                        $this->enqueueOutbound(new PingMessage(), true);
                    } catch (Throwable) {
                        // Ignore ping enqueue failures. Reconnect path is handled by regular state checks.
                    }
                },
                function (): void {
                    if (
                        $this->configuration->isPingReconnectOnTimeout()
                        && $this->status === ClientState::CONNECTED
                    ) {
                        $this->tryReconnect(new ConnectionException('Ping timeout'));
                    }
                },
            );
        }

        if ($this->status === ClientState::CONNECTED && $this->connectedEventPending) {
            $this->connectedEventPending = false;
            $this->eventDispatcher->dispatch(self::STATUS_EVENT_NAME, ClientState::CONNECTED);
        }
    }

    private function restoreSubscriptionsImmediately(): void
    {
        foreach ($this->subscriptionsBySid as $sid => $subject) {
            $this->sendImmediate(new SubMessage($subject, $sid));
        }
    }

    public function getState(): ClientState
    {
        return $this->status;
    }

    /** @return list<ServerAddress> */
    public function getKnownServers(): array
    {
        return $this->serverPool->allServers();
    }

    public function getCurrentServer(): ServerAddress|null
    {
        return $this->serverPool->getCurrent();
    }

    private function isReconnectBackoffContextActive(int $epoch): bool
    {
        return $this->status === ClientState::RECONNECTING && $this->activeReconnectBackoffEpoch === $epoch;
    }

    private function isStateObservable(ClientState $state): bool
    {
        if ($this->status !== $state) {
            return false;
        }

        return $state !== ClientState::CONNECTED || !$this->connectedEventPending;
    }

    private function discoverServersFromInfo(InfoMessageInterface $message): void
    {
        $tlsEnabled = $this->serverPool->getCurrent()?->isTlsEnabled() ?? false;
        $servers    = $this->infoConnectUrlsExtractor->extract($message, $tlsEnabled);
        if ($servers === []) {
            return;
        }

        $this->serverPool->addDiscoveredServers($servers);
    }

    private function enqueueInboundDispatchResponse(
        PubMessageInterface|HPubMessageInterface $message,
    ): void {
        assert($message instanceof NatsProtocolMessageInterface);

        $state = $this->assertCanEnqueueInboundDispatchResponse();

        $this->enqueueFrame($message, $state);
    }

    private function assertCanEnqueueOutbound(bool $allowBufferWhileReconnecting): ClientState
    {
        $state = $this->status;
        if (in_array($state, [ClientState::DRAINING, ClientState::CLOSED], true)) {
            throw new ConnectionException(sprintf('Could not publish while client state is %s', $state->value));
        }

        if ($state === ClientState::NEW) {
            throw new ClientNotConnectedException('Client is not connected yet');
        }

        if (
            in_array($state, [ClientState::CONNECTING, ClientState::RECONNECTING], true)
            && !$this->configuration->isBufferWhileReconnecting()
            && !$allowBufferWhileReconnecting
        ) {
            throw new ClientNotConnectedException(sprintf(
                'Could not publish while client state is %s',
                $state->value,
            ));
        }

        return $state;
    }

    private function assertCanEnqueueInboundDispatchResponse(): ClientState
    {
        $state = $this->status;
        if ($state === ClientState::CLOSED) {
            throw new ConnectionException(sprintf('Could not publish while client state is %s', $state->value));
        }

        if (
            in_array($state, [ClientState::CONNECTING, ClientState::RECONNECTING], true)
            && !$this->configuration->isBufferWhileReconnecting()
        ) {
            throw new ClientNotConnectedException(sprintf(
                'Could not publish while client state is %s',
                $state->value,
            ));
        }

        return $state;
    }

    private function enqueueFrame(NatsProtocolMessageInterface $message, ClientState $state): void
    {
        $frame = $this->outboundFrameBuilder->build($message);

        try {
            $this->writeBuffer->enqueue($frame);
        } catch (WriteBufferOverflowException $exception) {
            $this->logger?->warning('Outbound write buffer overflow', [
                'state' => $state->value,
                'frame_bytes' => $frame->bytes,
                'pending_messages' => $this->writeBuffer->getPendingMessages(),
                'pending_bytes' => $this->writeBuffer->getPendingBytes(),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
