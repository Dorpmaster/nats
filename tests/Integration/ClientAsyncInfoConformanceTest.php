<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\NullCancellation;
use Amp\Socket\DnsSocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\PingServiceInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\FakeNatsProtocolServer;
use Dorpmaster\Nats\Tests\Support\ObservedConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class ClientAsyncInfoConformanceTest extends TestCase
{
    use AsyncTestTools;

    public function testSecondInfoBeforePongKeepsOneHandshakeAndTheQueuePaused(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $states = [];
            $ping   = $this->createMock(PingServiceInterface::class);
            $ping->expects(self::once())->method('start');
            [$client, $dispatcher] = $this->client($server, $states, $ping);
            try {
                $client->connect();
                $client->subscribe('persisted', static fn(): null => null);
                $client->publish(new PubMessage('queued', 'first'));
                $client->publish(new PubMessage('queued', 'second'));
                $server->awaitCommandCount(0, 2);
                $server->sendInfo(0, ['server_name' => 'updated', 'connect_urls' => ['127.0.0.2:4223']]);
                $server->sendPing(0);
                $server->awaitCommandNameCount(0, 'PONG', 1);
                $this->trace($this->nameWithDataSet() . '-checkpoint', $server, [0]);
                self::assertSame(['CONNECT', 'PING', 'PONG'], $server->commandNames(0));
                self::assertNotContains(ClientState::CONNECTED, $states);
                self::assertSame('updated', $dispatcher->getServerInfo()->server_name);
                self::assertContains('127.0.0.2', array_map(static fn($address): string => $address->getHost(), $client->getKnownServers()));
                $server->releasePong(0);
                $server->awaitCommandNameCount(0, 'PUB', 2);
                self::assertSame(['SUB', 'PUB', 'PUB'], array_slice($server->commandNames(0), 3, 3));
                $this->requestBarrier($client, $server, 0);
                self::assertSame(1, self::countCommand($server, 0, 'CONNECT'));
                self::assertSame(1, self::countCommand($server, 0, 'PING'));
                self::assertSame(1, count(array_filter($states, static fn($state): bool => $state === ClientState::CONNECTED)));
                self::assertFalse($server->authorizationViolationSent(0));
                $this->trace($this->nameWithDataSet(), $server, [0]);
            } finally {
                $server->releasePong(0);
                $this->forceTick();
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public static function dispatcherKinds(): iterable
    {
        yield 'built-in' => [false];
        yield 'third-party interface implementation' => [true];
    }

    #[DataProvider('dispatcherKinds')]
    public function testRepeatedInfoAfterReadyUpdatesMetadataWithoutNewHandshake(bool $customDispatcher): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($customDispatcher): void {
            $server = new FakeNatsProtocolServer();
            $states = [];
            $ping   = $this->createMock(PingServiceInterface::class);
            $ping->expects(self::once())->method('start');
            [$client, $dispatcher] = $this->client($server, $states, $ping, customDispatcher: $customDispatcher);
            try {
                $client->connect();
                $server->awaitCommandCount(0, 2);
                $server->releasePong(0);
                $this->requestBarrier($client, $server, 0);
                $client->subscribe('persisted', static fn(): null => null);
                foreach ([['server_name' => 'new-name', 'connect_urls' => ['127.0.0.2:4223']], ['server_name' => 'without-urls', 'max_payload' => 2048], ['server_name' => 'latest']] as $update) {
                    $server->sendInfo(0, $update);
                    $this->requestBarrier($client, $server, 0);
                }
                $this->trace($this->nameWithDataSet() . '-checkpoint', $server, [0]);
                self::assertSame('latest', $dispatcher->getServerInfo()->server_name);
                self::assertNull($dispatcher->getServerInfo()->connect_urls);
                self::assertContains('127.0.0.2', array_map(static fn($address): string => $address->getHost(), $client->getKnownServers()));
                self::assertSame(1, self::countCommand($server, 0, 'CONNECT'));
                self::assertSame(1, self::countCommand($server, 0, 'PING'));
                self::assertCount(1, array_filter($server->rawCommands(0), static fn(string $command): bool => str_starts_with($command, 'SUB persisted ')));
                self::assertSame(1, count(array_filter($states, static fn($state): bool => $state === ClientState::CONNECTED)));
                self::assertFalse($server->authorizationViolationSent(0));
                $this->trace($this->nameWithDataSet(), $server, [0]);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public static function reopenKinds(): iterable
    {
        yield 'reconnect' => [false];
        yield 'disconnect then connect' => [true];
    }

    #[DataProvider('reopenKinds')]
    public function testEachNewConnectionHandshakesOnceAndReplaysBeforeBufferedPublishes(bool $explicitDisconnect): void
    {
        $this->setTimeout(15);
        $this->runAsyncTest(function () use ($explicitDisconnect): void {
            $server   = new FakeNatsProtocolServer();
            $states   = [];
            [$client] = $this->client($server, $states, configuration: $this->reconnectConfiguration());
            try {
                $client->connect();
                $client->subscribe('persisted', static fn(): null => null);
                $server->awaitCommandCount(0, 2);
                $server->releasePong(0);
                $this->requestBarrier($client, $server, 0);
                if ($explicitDisconnect) {
                    $client->disconnect();
                    $client->connect();
                    $client->subscribe('persisted', static fn(): null => null);
                } else {
                    $server->closeSession(0);
                }
                $server->awaitSessionCount(2);
                $server->awaitCommandCount(1, 2);
                $client->publish(new PubMessage('buffered', 'first'));
                $client->publish(new PubMessage('buffered', 'second'));
                $server->sendInfo(1, ['server_name' => 'second-transport']);
                $server->sendPing(1);
                $server->awaitCommandNameCount(1, 'PONG', 1);
                self::assertSame(['CONNECT', 'PING', 'PONG'], $server->commandNames(1));
                $server->releasePong(1);
                $server->awaitCommandNameCount(1, 'PUB', 2);
                self::assertSame(['SUB', 'PUB', 'PUB'], array_slice($server->commandNames(1), 3, 3));
                self::assertStringStartsWith('SUB persisted ', $server->rawCommands(1)[3]);
                $this->requestBarrier($client, $server, 1);
                foreach ([0, 1] as $session) {
                    self::assertSame(1, self::countCommand($server, $session, 'CONNECT'));
                    self::assertSame(1, self::countCommand($server, $session, 'PING'));
                    self::assertFalse($server->authorizationViolationSent($session));
                }
                self::assertSame(2, count(array_filter($states, static fn($state): bool => $state === ClientState::CONNECTED)));
                $this->trace($this->nameWithDataSet(), $server, [0, 1]);
            } finally {
                $server->releasePong(1);
                $this->forceTick();
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public static function failedCommands(): iterable
    {
        yield 'CONNECT failure with reconnect' => ['CONNECT', true];
        yield 'handshake PING failure with reconnect' => ['PING', true];
        yield 'CONNECT failure then explicit connect' => ['CONNECT', false];
        yield 'handshake PING failure then explicit connect' => ['PING', false];
    }

    #[DataProvider('failedCommands')]
    public function testHandshakeWriteFailureDoesNotSuppressHandshakeOnNextConnection(string $failedCommand, bool $automaticReconnect): void
    {
        $this->setTimeout(15);
        $this->runAsyncTest(function () use ($failedCommand, $automaticReconnect): void {
            $server     = new FakeNatsProtocolServer();
            $states     = [];
            $connection = new ObservedConnection(new Connection(new DnsSocketConnector(), new ConnectionConfiguration($server->host(), $server->port())), $failedCommand);
            [$client]   = $this->client($server, $states, connection: $connection, configuration: $automaticReconnect ? $this->reconnectConfiguration() : new ClientConfiguration());
            try {
                $client->connect();
                $client->publish(new PubMessage('buffered', 'after-failed-handshake'));
                if (!$automaticReconnect) {
                    $deadline = hrtime(true) + 2_000_000_000;
                    while ($client->getState() !== ClientState::CLOSED && hrtime(true) < $deadline) {
                        \Amp\delay(0.001);
                    }
                    self::assertSame(ClientState::CLOSED, $client->getState());
                    self::assertTrue($connection->isClosed());
                    $client->connect();
                    $client->publish(new PubMessage('buffered', 'fresh-connect'));
                }
                $server->awaitSessionCount(2, 3);
                $server->awaitCommandCount(1, 2);
                $server->sendInfo(1);
                $server->sendPing(1);
                $server->awaitCommandNameCount(1, 'PONG', 1);
                self::assertSame(['CONNECT', 'PING', 'PONG'], $server->commandNames(1));
                $server->releasePong(1);
                $server->awaitCommandNameCount(1, 'PUB', 1);
                $this->requestBarrier($client, $server, 1);
                self::assertSame(1, self::countCommand($server, 1, 'CONNECT'));
                self::assertSame(1, self::countCommand($server, 1, 'PING'));
                self::assertSame($failedCommand === 'CONNECT' ? ['CONNECT'] : ['CONNECT', 'PING'], $connection->attempts[0]);
                self::assertSame(1, count(array_filter($states, static fn($state): bool => $state === ClientState::CONNECTED)));
                self::assertContains($automaticReconnect ? ClientState::RECONNECTING : ClientState::CLOSED, $states);
                $this->trace($this->nameWithDataSet(), $server, [0, 1]);
            } finally {
                $server->releasePong(1);
                $this->forceTick();
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testPeriodicClientPingAndServerPingStillWorkBetweenInfoUpdates(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server   = new FakeNatsProtocolServer();
            $states   = [];
            [$client] = $this->client($server, $states, configuration: new ClientConfiguration(pingEnabled: true, pingIntervalMs: 30, pingTimeoutMs: 1000));
            try {
                $client->connect();
                $server->awaitCommandCount(0, 2);
                $server->releasePong(0);
                $server->awaitCommandNameCount(0, 'PING', 2);
                $server->sendInfo(0, ['server_name' => 'periodic-info']);
                $this->requestBarrier($client, $server, 0);
                self::assertSame(1, self::countCommand($server, 0, 'CONNECT'));
                self::assertGreaterThanOrEqual(2, self::countCommand($server, 0, 'PING'));
                self::assertGreaterThanOrEqual(1, self::countCommand($server, 0, 'PONG'));
                self::assertSame(1, count(array_filter($states, static fn($state): bool => $state === ClientState::CONNECTED)));
                $this->trace($this->nameWithDataSet(), $server, [0]);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    private function reconnectConfiguration(): ClientConfiguration
    {
        return new ClientConfiguration(reconnectEnabled: true, maxReconnectAttempts: 5, reconnectBackoffInitialMs: 1, reconnectBackoffMaxMs: 1, reconnectJitterFraction: 0.0, bufferWhileReconnecting: true);
    }

    /** @param list<int> $sessions */
    private function trace(string $case, FakeNatsProtocolServer $server, array $sessions): void
    {
        $directory = getenv('NATS_T5_AUDIT_DIR');
        if ($directory === false || !is_dir($directory)) {
            return;
        }
        $commands = [];
        foreach ($sessions as $session) {
            $commands[$session] = $server->commandNames($session);
        }
        file_put_contents($directory . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $case) . '.json', json_encode(['case' => $case, 'connections' => $commands], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function requestBarrier(Client $client, FakeNatsProtocolServer $server, int $session): void
    {
        $pubs   = self::countCommand($server, $session, 'PUB');
        $unsubs = self::countCommand($server, $session, 'UNSUB');
        $future = async(static fn() => $client->request(new PubMessage('rpc.control', 'control'), 1));
        $server->awaitCommandNameCount($session, 'PUB', $pubs + 1);
        $server->replyToLatestRequest($session, 'ok');
        self::assertSame('ok', $future->await()->getPayload());
        $server->awaitCommandNameCount($session, 'UNSUB', $unsubs + 1);
        $pongs = self::countCommand($server, $session, 'PONG');
        $server->sendPing($session);
        $server->awaitCommandNameCount($session, 'PONG', $pongs + 1);
    }

    private static function countCommand(FakeNatsProtocolServer $server, int $session, string $command): int
    {
        return count(array_filter($server->commandNames($session), static fn(string $value): bool => $value === $command));
    }

    /** @param list<ClientState> $states
     * @return array{Client, MessageDispatcherInterface}
     */
    private function client(FakeNatsProtocolServer $server, array &$states, PingServiceInterface|null $ping = null, ConnectionInterface|null $connection = null, ClientConfiguration|null $configuration = null, bool $customDispatcher = false): array
    {
        $storage    = new SubscriptionStorage();
        $dispatcher = new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, protocol: 1), $storage, $this->logger);
        if ($customDispatcher) {
            $dispatcher = new class ($dispatcher) implements MessageDispatcherInterface {
                public function __construct(private MessageDispatcherInterface $inner)
                {
                }
                public function dispatch(NatsProtocolMessageInterface $message): NatsProtocolMessageInterface|null
                {
                    return $this->inner->dispatch($message);
                }
                public function getServerInfo(): ServerInfo|null
                {
                    return $this->inner->getServerInfo();
                }
            };
        }
        $events = new EventDispatcher();
        $events->subscribe('connectionStatusChanged', static function (string $event, ClientState $state) use (&$states): void {
            $states[] = $state;
        });
        return [new Client(
            configuration: $configuration ?? new ClientConfiguration(pingEnabled: $ping !== null),
            cancellation: new NullCancellation(),
            connection: $connection ?? new Connection(new DnsSocketConnector(), new ConnectionConfiguration($server->host(), $server->port())),
            eventDispatcher: $events,
            messageDispatcher: $dispatcher,
            storage: $storage,
            logger: $this->logger,
            pingService: $ping,
        ), $dispatcher];
    }
}
