<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\InboundDispatchScheduler;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\FakePingService;
use Dorpmaster\Nats\Tests\Support\SpyWriteBuffer;
use Dorpmaster\Nats\Tests\Support\SynchronousEventDispatcher;
use PHPUnit\Framework\TestCase;

final class ClientTransitionEffectsTest extends TestCase
{
    public function testConnectedEventSeesReadyConnectedLifecycle(): void
    {
        $pingService     = new FakePingService();
        $writeBuffer     = new SpyWriteBuffer();
        $eventDispatcher = new SynchronousEventDispatcher();
        $client          = $this->createClient($eventDispatcher, $pingService, $writeBuffer);

        $observed = null;
        $eventDispatcher->subscribe('connectionStatusChanged', function (string $eventName, mixed $payload) use (&$observed, $client, $pingService, $writeBuffer): void {
            if ($eventName !== 'connectionStatusChanged' || $payload !== ClientState::CONNECTED) {
                return;
            }

            $observed = $this->snapshot($client, $pingService, $writeBuffer);
        });

        $this->moveToState($client, ClientState::CONNECTING, 'test');
        $this->moveToState($client, ClientState::CONNECTED, 'test');
        $this->completeHandshake($client);

        self::assertNotNull($observed);
        self::assertSame(ClientState::CONNECTED, $observed['state']);
        self::assertTrue($observed['handshake_ready']);
        self::assertFalse($observed['connected_event_pending']);
        self::assertTrue($observed['ping_running']);
        self::assertTrue($observed['write_buffer_started']);
        self::assertFalse($observed['write_buffer_paused']);
        self::assertFalse($observed['write_buffer_stopped']);
        self::assertTrue($observed['scheduler_accepting']);
        self::assertNull($observed['reconnect_cancellation']);
    }

    public function testReconnectingEventSeesReconnectLifecycleInitialized(): void
    {
        $pingService     = new FakePingService();
        $writeBuffer     = new SpyWriteBuffer();
        $eventDispatcher = new SynchronousEventDispatcher();
        $client          = $this->createClient($eventDispatcher, $pingService, $writeBuffer);

        $this->moveToState($client, ClientState::CONNECTING, 'test');
        $this->moveToState($client, ClientState::CONNECTED, 'test');
        $this->completeHandshake($client);

        $observed = null;
        $eventDispatcher->subscribe('connectionStatusChanged', function (string $eventName, mixed $payload) use (&$observed, $client, $pingService, $writeBuffer): void {
            if ($eventName !== 'connectionStatusChanged' || $payload !== ClientState::RECONNECTING) {
                return;
            }

            $observed = $this->snapshot($client, $pingService, $writeBuffer);
        });

        $this->moveToState($client, ClientState::RECONNECTING, 'test');

        self::assertNotNull($observed);
        self::assertSame(ClientState::RECONNECTING, $observed['state']);
        self::assertFalse($observed['ping_running']);
        self::assertFalse($observed['scheduler_accepting']);
        self::assertNotNull($observed['reconnect_cancellation']);
        self::assertNotNull($observed['reconnect_epoch']);
        self::assertTrue($observed['write_buffer_stopped']);
    }

    public function testDrainingEventSeesDrainRestrictionsAlreadyApplied(): void
    {
        $pingService     = new FakePingService();
        $writeBuffer     = new SpyWriteBuffer();
        $eventDispatcher = new SynchronousEventDispatcher();
        $client          = $this->createClient($eventDispatcher, $pingService, $writeBuffer);

        $this->moveToState($client, ClientState::CONNECTING, 'test');
        $this->moveToState($client, ClientState::CONNECTED, 'test');
        $this->completeHandshake($client);

        $observed = null;
        $eventDispatcher->subscribe('connectionStatusChanged', function (string $eventName, mixed $payload) use (&$observed, $client, $pingService, $writeBuffer): void {
            if ($eventName !== 'connectionStatusChanged' || $payload !== ClientState::DRAINING) {
                return;
            }

            $snapshot = $this->snapshot($client, $pingService, $writeBuffer);
            try {
                $client->publish(new PubMessage('test', 'payload'));
                self::fail('Publish during DRAINING must be rejected');
            } catch (ConnectionException $exception) {
                $snapshot['publish_exception'] = $exception->getMessage();
            }

            $observed = $snapshot;
        });

        $this->moveToState($client, ClientState::DRAINING, 'test');

        self::assertNotNull($observed);
        self::assertSame(ClientState::DRAINING, $observed['state']);
        self::assertFalse($observed['ping_running']);
        self::assertFalse($observed['scheduler_accepting']);
        self::assertSame('Could not publish while client state is DRAINING', $observed['publish_exception']);
    }

    public function testTransitionToOnlyCommitsStateWithoutDispatchingEffects(): void
    {
        $pingService     = new FakePingService();
        $writeBuffer     = new SpyWriteBuffer();
        $eventDispatcher = new SynchronousEventDispatcher();
        $client          = $this->createClient($eventDispatcher, $pingService, $writeBuffer);

        $events = [];
        $eventDispatcher->subscribe('connectionStatusChanged', static function (string $eventName, mixed $payload) use (&$events): void {
            $events[] = [$eventName, $payload];
        });

        $this->commitTransitionOnly($client, ClientState::CONNECTING, 'test');
        $this->commitTransitionOnly($client, ClientState::CONNECTED, 'test');

        self::assertSame(ClientState::CONNECTED, $client->getState());
        self::assertSame([], $events);
        self::assertFalse($pingService->isRunning());
        self::assertFalse($writeBuffer->wasStarted());
    }

    private function createClient(
        SynchronousEventDispatcher $eventDispatcher,
        FakePingService $pingService,
        SpyWriteBuffer $writeBuffer,
    ): Client {
        $messageDispatcher = self::createStub(MessageDispatcherInterface::class);
        $messageDispatcher->method('dispatch')->willReturn(null);
        $storage = self::createStub(SubscriptionStorageInterface::class);
        $storage->method('all')->willReturn([]);

        $connection = self::createStub(ConnectionInterface::class);
        $connection->method('isClosed')->willReturn(false);
        $connection->method('close');

        return new Client(
            configuration: new ClientConfiguration(
                pingEnabled: true,
                reconnectEnabled: true,
                reconnectJitterFraction: 0.0,
            ),
            cancellation: new NullCancellation(),
            connection: $connection,
            eventDispatcher: $eventDispatcher,
            messageDispatcher: $messageDispatcher,
            storage: $storage,
            pingService: $pingService,
            writeBuffer: $writeBuffer,
        );
    }

    /**
     * @return array{
     *     state: ClientState,
     *     handshake_ready: bool,
     *     connected_event_pending: bool,
     *     reconnect_cancellation: object|null,
     *     reconnect_epoch: int|null,
     *     scheduler_accepting: bool,
     *     ping_running: bool,
     *     write_buffer_started: bool,
     *     write_buffer_paused: bool,
     *     write_buffer_stopped: bool
     * }
     */
    private function snapshot(Client $client, FakePingService $pingService, SpyWriteBuffer $writeBuffer): array
    {
        $inspectScheduler = \Closure::bind(
            static function (InboundDispatchScheduler $scheduler): bool {
                return $scheduler->getActiveCount() === 0 && $scheduler->getPendingCount() === 0
                    ? $scheduler->accepting
                    : $scheduler->accepting;
            },
            null,
            InboundDispatchScheduler::class,
        );

        $inspect = \Closure::bind(
            static function (Client $target) use ($pingService, $writeBuffer, $inspectScheduler): array {
                return [
                    'state' => $target->status,
                    'handshake_ready' => $target->handshakeReady,
                    'connected_event_pending' => $target->connectedEventPending,
                    'reconnect_cancellation' => $target->reconnectBackoffCancellation,
                    'reconnect_epoch' => $target->activeReconnectBackoffEpoch,
                    'scheduler_accepting' => $inspectScheduler($target->inboundDispatchScheduler),
                    'ping_running' => $pingService->isRunning(),
                    'write_buffer_started' => $writeBuffer->wasStarted(),
                    'write_buffer_paused' => $writeBuffer->isPaused(),
                    'write_buffer_stopped' => $writeBuffer->isStopped(),
                ];
            },
            null,
            Client::class,
        );

        return $inspect($client);
    }

    private function moveToState(Client $client, ClientState $state, string $reason): void
    {
        $move = \Closure::bind(
            static function (Client $target, ClientState $state, string $reason): void {
                $target->moveToState($state, $reason);
            },
            null,
            Client::class,
        );

        $move($client, $state, $reason);
    }

    private function commitTransitionOnly(Client $client, ClientState $state, string $reason): void
    {
        $commit = \Closure::bind(
            static function (Client $target, ClientState $state, string $reason): void {
                $target->transitionTo($state, $reason);
            },
            null,
            Client::class,
        );

        $commit($client, $state, $reason);
    }

    private function completeHandshake(Client $client): void
    {
        $complete = \Closure::bind(
            static function (Client $target): void {
                $target->completeHandshake();
            },
            null,
            Client::class,
        );

        $complete($client);
    }
}
