<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\NullCancellation;
use Amp\Socket\DnsSocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Client\WriteBufferService;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Client\RequestWriteException;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\CapturedProtocolLogs;
use Dorpmaster\Nats\Tests\Support\ControlledWriteConnection;
use Dorpmaster\Nats\Tests\Support\FakeNatsProtocolServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

final class JetStreamUpdateWriteConformanceTest extends TestCase
{
    use AsyncTestTools;

    private const string CONFIG = '{"config":{"name":"S","subjects":["s"],"max_consumers":7}}';
    private const string UPDATE = 'PUB $JS.API.STREAM.UPDATE.S ';
    private CapturedProtocolLogs $logs;

    public function testUncertainDeliveredUpdateFailsOnceAndIsNeverReplayed(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server            = new FakeNatsProtocolServer();
            $cause             = new ConnectionException('synthetic-uncertain-write-secret');
            $late              = null;
            $storage           = new SubscriptionStorage();
            $connection        = $this->connection($server, after: static function ($message, int $session) use ($server, $cause, $storage, &$late): void {
                if (str_starts_with((string) $message, self::UPDATE)) {
                    $server->awaitCommandNameCount($session, 'PUB', 3);
                    $late = array_values($storage->all())[0];
                    throw $cause;
                }
            });
            [$client, $buffer] = $this->client($server, $connection, $storage);
            try {
                $this->ready($client, $server);
                $future = $this->update($client, $server, 1000);
                try {
                    $future->await();
                    self::fail('Uncertain UPDATE cannot return success');
                } catch (RequestWriteException $error) {
                    self::assertSame($cause, $error->getPrevious());
                }
                self::assertSame([], $storage->all());
                self::assertFalse($buffer->hasFailedFrame());
                self::assertCount(1, $this->updates($server));
                $late(new MsgMessage('late', '1', self::CONFIG));
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                self::assertCount(1, $this->updates($server), 'No second UPDATE after the control exchange');
                $this->assertEmptyBuffer($buffer);
                self::assertSame([], $storage->all());
                $this->trace($server, $buffer, 'uncertain_write', true);
            } finally {
                $client->disconnect();
                $server->stop();
                $this->logs->assertSafe(['synthetic-uncertain-write-secret']);
            }
        });
    }

    public function testTimeoutWinsBeforeLateWriteFailureWithoutReplay(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server            = new FakeNatsProtocolServer();
            $release           = new DeferredFuture();
            $storage           = new SubscriptionStorage();
            $connection        = $this->connection($server, after: static function ($message, int $session) use ($server, $release): void {
                if (str_starts_with((string) $message, self::UPDATE)) {
                    $server->awaitCommandNameCount($session, 'PUB', 3);
                    $release->getFuture()->await();
                    throw new ConnectionException('synthetic-late-write-secret');
                }
            });
            [$client, $buffer] = $this->client($server, $connection, $storage);
            try {
                $this->ready($client, $server);
                $future = $this->update($client, $server, 150);
                try {
                    $future->await();
                    self::fail('Expected deadline');
                } catch (JetStreamTimeoutException $error) {
                    self::assertInstanceOf(CancelledException::class, $error->getPrevious());
                    $primary = $error;
                }
                self::assertCount(1, $this->updates($server));
                self::assertSame([], $storage->all());
                $release->complete();
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                try {
                    $future->await();
                    self::fail('Original timeout must remain the result');
                } catch (JetStreamTimeoutException $again) {
                    self::assertSame($primary, $again);
                }
                self::assertCount(1, $this->updates($server));
                $this->assertEmptyBuffer($buffer);
                $this->trace($server, $buffer, 'original_timeout', true);
            } finally {
                if (!$release->isComplete()) {
                    $release->complete();
                }
                $client->disconnect();
                $server->stop();
                $this->logs->assertSafe(['synthetic-late-write-secret']);
            }
        });
    }

    public static function expiredQueue(): iterable
    {
        yield 'paused timeout' => [false, false];
        yield 'paused cancellation' => [false, true];
        yield 'reconnect timeout before PONG' => [true, false];
    }

    #[DataProvider('expiredQueue')]
    public function testExpiredUnsentUpdateAndInboxAreRemovedBeforeResume(bool $reconnect, bool $cancel): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($reconnect, $cancel): void {
            $server            = new FakeNatsProtocolServer();
            $storage           = new SubscriptionStorage();
            $source            = new DeferredCancellation();
            [$client, $buffer] = $this->client($server, $this->connection($server), $storage, $source->getCancellation());
            try {
                $this->ready($client, $server);
                $future = async(static fn () => (new JetStreamAdmin(new JetStreamControlPlaneTransport($client)))->updateStream('S', new StreamConfigPatch(maxConsumers: 7), 300));
                $server->awaitCommandNameCount(0, 'PUB', 2);
                $infoSub = array_values(array_filter($server->rawCommands(0), static fn (string $line): bool => str_starts_with($line, 'SUB ')));
                $infoSid = substr($infoSub[array_key_last($infoSub)], strrpos($infoSub[array_key_last($infoSub)], ' ') + 1);
                $buffer->pause();
                $server->replyToLatestRequest(0, self::CONFIG);
                $this->waitFor(static fn (): bool => $buffer->getPendingMessages() === 3);
                $updateSid = array_key_first($storage->all());
                $late      = $storage->get($updateSid);
                if ($reconnect) {
                    $server->closeSession(0);
                    $session = $this->reconnectSession($server);
                } else {
                    $session = 0;
                }
                if ($cancel) {
                    $source->cancel(new RuntimeException('synthetic-cancel-secret'));
                }
                try {
                    $future->await();
                    self::fail('Expired request must fail');
                } catch (JetStreamTimeoutException $error) {
                    self::assertInstanceOf(CancelledException::class, $error->getPrevious());
                }
                self::assertSame([], $storage->all());
                self::assertFalse($buffer->hasFailedFrame());
                self::assertCount(0, $this->updates($server));
                // Only legitimate cleanup UNSUB commands remain; the request SUB and UPDATE are gone.
                self::assertSame(2, $buffer->getPendingMessages());
                self::assertSame(strlen("UNSUB $infoSid\r\n") + strlen("UNSUB $updateSid\r\n"), $buffer->getPendingBytes());
                $late(new MsgMessage('late', '1', self::CONFIG));
                if ($cancel) {
                    // The provided cancellation is global to Client: shut down while still
                    // paused, rather than expecting a cancelled client's reader to answer PING.
                    $client->disconnect();
                    $this->forceTick();
                } else {
                    if ($reconnect) {
                        $server->releasePong($session);
                    } else {
                        $buffer->resume();
                    }
                    $this->barrier($client, $server, $session);
                }
                self::assertCount(0, $this->updates($server));
                $this->assertEmptyBuffer($buffer);
                for ($index = 0; $index < 6; $index++) {
                    $expiredSubs = array_filter($server->rawCommands($index), static fn (string $line): bool => str_starts_with($line, 'SUB ') && str_ends_with($line, ' ' . $updateSid));
                    self::assertSame([], array_values($expiredSubs), 'Expired request inbox must never be sent or replayed');
                }
                $this->trace($server, $buffer, $cancel ? 'cancelled_client' : 'unsent_timeout', !$cancel);
            } finally {
                $client->disconnect();
                $server->stop();
                $this->logs->assertSafe(['synthetic-cancel-secret']);
            }
        });
    }

    public function testSuccessReleasesTimerAndIgnoresDuplicateCompletion(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server            = new FakeNatsProtocolServer();
            $storage           = new SubscriptionStorage();
            [$client, $buffer] = $this->client($server, $this->connection($server), $storage);
            try {
                $this->ready($client, $server);
                $timers = $this->timers();
                $future = $this->update($client, $server, 1000);
                $server->awaitCommandNameCount(0, 'PUB', 3);
                $late = array_values($storage->all())[0];
                $server->replyToLatestRequest(0, self::CONFIG);
                self::assertSame('S', $future->await()->getName());
                $late(new MsgMessage('duplicate', '1', self::CONFIG));
                $this->forceTick();
                self::assertSame($timers, $this->timers());
                self::assertSame([], $storage->all());
                $this->barrier($client, $server, 0);
                self::assertCount(1, $this->updates($server));
                $this->assertEmptyBuffer($buffer);
                $this->trace($server, $buffer, 'confirmed', true);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testOrdinaryPublicationStillReplaysTheSameSnapshot(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server            = new FakeNatsProtocolServer();
            $writes            = [];
            $connection        = $this->connection($server, after: static function ($message, int $session) use ($server, &$writes): void {
                if (str_starts_with((string) $message, 'PUB ordinary.replay ')) {
                    $writes[] = (string) $message;
                    if (count($writes) === 1) {
                        $server->awaitCommandNameCount($session, 'PUB', 2);
                        throw new ConnectionException('synthetic-publish-retry');
                    }
                }
            });
            [$client, $buffer] = $this->client($server, $connection, new SubscriptionStorage());
            try {
                $this->ready($client, $server);
                $client->publish(new PubMessage('ordinary.replay', "AB\r\n\0"));
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                self::assertCount(2, $writes);
                self::assertSame($writes[0], $writes[1]);
                $this->assertEmptyBuffer($buffer);
                $this->trace($server, $buffer, 'ordinary_snapshot_replayed', true);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    private function connection(FakeNatsProtocolServer $server, \Closure|null $before = null, \Closure|null $after = null): ControlledWriteConnection
    {
        return new ControlledWriteConnection(new Connection(new DnsSocketConnector(), new ConnectionConfiguration($server->host(), $server->port())), $before, $after);
    }

    /** @return array{Client, WriteBufferService} */
    private function client(FakeNatsProtocolServer $server, ControlledWriteConnection $connection, SubscriptionStorage $storage, Cancellation|null $cancellation = null): array
    {
        $this->logs = new CapturedProtocolLogs();
        $buffer     = new WriteBufferService(100, 100000);
        $client     = new Client(
            configuration: new ClientConfiguration(reconnectEnabled: true, maxReconnectAttempts: 5, reconnectBackoffInitialMs: 1, reconnectBackoffMaxMs: 1, reconnectJitterFraction: 0.0, bufferWhileReconnecting: true),
            cancellation: $cancellation ?? new NullCancellation(),
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, protocol: 1), $storage, $this->logs),
            storage: $storage,
            logger: $this->logs,
            writeBuffer: $buffer,
        );
        return [$client, $buffer];
    }

    private function ready(Client $client, FakeNatsProtocolServer $server): void
    {
        $client->connect();
        $server->awaitCommandNameCount(0, 'PING', 1);
        $server->releasePong(0);
        $this->barrier($client, $server, 0);
    }

    private function update(Client $client, FakeNatsProtocolServer $server, int $timeout): \Amp\Future
    {
        $future = async(static fn () => (new JetStreamAdmin(new JetStreamControlPlaneTransport($client)))->updateStream('S', new StreamConfigPatch(maxConsumers: 7), $timeout));
        $server->awaitCommandNameCount(0, 'PUB', 2);
        $server->replyToLatestRequest(0, self::CONFIG);
        return $future;
    }

    private function barrier(Client $client, FakeNatsProtocolServer $server, int $session): void
    {
        $pubs   = count(array_filter($server->commandNames($session), static fn (string $name): bool => $name === 'PUB'));
        $future = async(static fn () => $client->request(new PubMessage('probe.next', 'control'), 1));
        $server->awaitCommandNameCount($session, 'PUB', $pubs + 1);
        $server->replyToLatestRequest($session, 'ok');
        self::assertSame('ok', $future->await()->getPayload());
        $this->forceTick();
        $pongs = count(array_filter($server->commandNames($session), static fn (string $name): bool => $name === 'PONG'));
        $server->sendPing($session);
        $server->awaitCommandNameCount($session, 'PONG', $pongs + 1);
    }

    private function reconnectSession(FakeNatsProtocolServer $server): int
    {
        $session = -1;
        $this->waitFor(static function () use ($server, &$session): bool {
            for ($index = 1; $index < 6; $index++) {
                if (in_array('PING', $server->commandNames($index), true)) {
                    $session = $index;
                    return true;
                }
            }
            return false;
        });
        return $session;
    }

    private function waitFor(\Closure $predicate): void
    {
        $deadline = hrtime(true) + 2_000_000_000;
        while (!$predicate()) {
            self::assertLessThan($deadline, hrtime(true), 'Controlled event-loop deadline');
            delay(0.001);
        }
    }

    /** @return list<string> */
    private function updates(FakeNatsProtocolServer $server): array
    {
        $updates = [];
        for ($index = 0; $index < 6; $index++) {
            array_push($updates, ...array_filter($server->rawCommands($index), static fn (string $line): bool => str_starts_with($line, self::UPDATE)));
        }
        return $updates;
    }

    private function assertEmptyBuffer(WriteBufferService $buffer): void
    {
        self::assertSame(0, $buffer->getPendingMessages());
        self::assertSame(0, $buffer->getPendingBytes());
        self::assertFalse($buffer->hasFailedFrame());
    }

    private function trace(FakeNatsProtocolServer $server, WriteBufferService $buffer, string $result, bool $nextRequest): void
    {
        $directory = getenv('NATS_UPDATE_TRACE_DIR');
        if ($directory === false || $directory === '') {
            return;
        }
        $connections = [];
        for ($index = 0; $index < 6; $index++) {
            $commands = $server->commandNames($index);
            if ($commands !== []) {
                $connections[$index] = [
                    'commands' => $commands,
                    'updates' => count(array_filter($server->rawCommands($index), static fn (string $line): bool => str_starts_with($line, self::UPDATE))),
                ];
            }
        }
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->nameWithDataSet());
        file_put_contents($directory . '/' . $name . '.json', json_encode([
            'result' => $result,
            'update_sends' => count($this->updates($server)),
            'next_request_success' => $nextRequest,
            'pending_messages' => $buffer->getPendingMessages(),
            'pending_bytes' => $buffer->getPendingBytes(),
            'failed_frame' => $buffer->hasFailedFrame(),
            'connections' => $connections,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @return list<string> */
    private function timers(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), static fn (string $id): bool => EventLoop::getType($id) === CallbackType::Delay));
    }
}
