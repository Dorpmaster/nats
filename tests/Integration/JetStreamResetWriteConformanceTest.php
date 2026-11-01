<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\CancelledException;
use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;
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

use function Amp\async;
use function Amp\delay;

final class JetStreamResetWriteConformanceTest extends TestCase
{
    use AsyncTestTools;

    private const string RESET    = 'PUB $JS.API.CONSUMER.RESET.S.C ';
    private const string RESPONSE = '{"type":"io.nats.jetstream.api.v1.consumer_reset_response","stream_name":"S","name":"C","config":{"durable_name":"C"},"reset_seq":7}';
    private CapturedProtocolLogs $logs;

    public function testUncertainWriteRetainsCauseAndNeverReplays(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server                      = new FakeNatsProtocolServer();
            $cause                       = new ConnectionException('synthetic-reset-write-secret');
            $connection                  = $this->connection($server, static function ($message, int $session) use ($server, $cause): void {
                if (str_starts_with((string) $message, self::RESET)) {
                    $server->awaitCommandNameCount($session, 'PUB', 2); // Recorded only after the full body and CRLF.
                    throw $cause;
                }
            });
            [$client, $buffer, $storage] = $this->client($server, $connection);
            try {
                $this->ready($client, $server);
                $future = $this->reset($client, 1000);
                try {
                    $future->await();
                    self::fail('Expected uncertain write error');
                } catch (RequestWriteException $error) {
                    self::assertSame($cause, $error->getPrevious());
                }
                self::assertSame([], $storage->all());
                self::assertFalse($buffer->hasFailedFrame());
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                $this->assertFinal($server, $buffer, 1);
                $this->trace($server, 'uncertain_write', 1);
            } finally {
                $client->disconnect();
                $server->stop();
                $this->logs->assertSafe(['synthetic-reset-write-secret']);
            }
        });
    }

    public function testLostReplyAfterCompleteResetNeverReplays(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server                      = new FakeNatsProtocolServer();
            [$client, $buffer, $storage] = $this->client($server, $this->connection($server));
            try {
                $this->ready($client, $server);
                $future = $this->reset($client, 150);
                $server->awaitCommandNameCount(0, 'PUB', 2);
                $late = array_values($storage->all())[0];
                self::assertSame('{"seq":7}', $this->resets($server)[0]['payload']);
                $server->closeSession(0);
                $session = $this->reconnectSession($server);
                try {
                    $future->await();
                    self::fail('Missing response cannot imply success');
                } catch (JetStreamTimeoutException $error) {
                    self::assertInstanceOf(CancelledException::class, $error->getPrevious());
                }
                $late(new MsgMessage('late', '1', self::RESPONSE));
                self::assertSame([], $storage->all());
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                $this->assertFinal($server, $buffer, 1);
                $this->trace($server, 'lost_reply', 1);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public static function pauses(): iterable
    {
        yield 'writer pause' => [false, false];
        yield 'cancelled writer pause' => [false, true];
        yield 'reconnect before READY' => [true, false];
    }

    #[DataProvider('pauses')]
    public function testExpiredQueuedResetAndInboxNeverSend(bool $reconnect, bool $cancel): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($reconnect, $cancel): void {
            $server                      = new FakeNatsProtocolServer();
            $source                      = new DeferredCancellation();
            [$client, $buffer, $storage] = $this->client($server, $this->connection($server), $source->getCancellation());
            try {
                $this->ready($client, $server);
                $buffer->pause();
                $future = $this->reset($client, 150);
                $this->waitFor(static fn (): bool => count($storage->all()) === 1 && $buffer->getPendingMessages() >= 2);
                $sid     = array_key_first($storage->all());
                $late    = $storage->get($sid);
                $session = 0;
                if ($reconnect) {
                    $server->closeSession(0);
                    $session = $this->reconnectSession($server);
                }
                if ($cancel) {
                    $source->cancel(new \RuntimeException('synthetic-reset-cancel-secret'));
                }
                try {
                    $future->await();
                    self::fail('Expired unsent reset must fail');
                } catch (JetStreamTimeoutException $error) {
                    self::assertInstanceOf(CancelledException::class, $error->getPrevious());
                }
                self::assertSame([], $storage->all());
                self::assertSame(1, $buffer->getPendingMessages());
                self::assertSame(strlen("UNSUB $sid\r\n"), $buffer->getPendingBytes());
                $late(new MsgMessage('late', '1', self::RESPONSE));
                if ($cancel) {
                    // Cancellation belongs to Client, so close it while its writer is still paused.
                    $client->disconnect();
                    $this->forceTick();
                } elseif ($reconnect) {
                    $server->releasePong($session);
                } else {
                    $buffer->resume();
                }
                if (!$cancel) {
                    $this->barrier($client, $server, $session);
                }
                $this->assertFinal($server, $buffer, 0);
                for ($index = 0; $index < 6; $index++) {
                    self::assertSame([], array_values(array_filter($server->rawCommands($index), static fn (string $line): bool => str_starts_with($line, 'SUB ') && str_ends_with($line, ' ' . $sid))));
                }
                $this->trace($server, $cancel ? 'cancelled_client' : ($reconnect ? 'expired_reconnect' : 'expired_pause'), 0, !$cancel);
                $this->logs->assertSafe(['synthetic-reset-cancel-secret']);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testSuccessAndLateCallbackFinishOnlyOnce(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server                      = new FakeNatsProtocolServer();
            [$client, $buffer, $storage] = $this->client($server, $this->connection($server));
            try {
                $this->ready($client, $server);
                $timers = $this->timers();
                $future = $this->reset($client, 1000);
                $server->awaitCommandNameCount(0, 'PUB', 2);
                $late = array_values($storage->all())[0];
                $server->replyToLatestRequest(0, self::RESPONSE);
                $result = $future->await();
                self::assertSame('7', $result->getResetSequence());
                $late(new MsgMessage('late', '1', str_replace('"reset_seq":7', '"reset_seq":9', self::RESPONSE)));
                self::assertSame('7', $result->getResetSequence());
                $this->forceTick();
                self::assertSame($timers, $this->timers());
                self::assertSame([], $storage->all());
                $this->barrier($client, $server, 0);
                $this->assertFinal($server, $buffer, 1);
                $this->trace($server, 'success', 1);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    private function connection(FakeNatsProtocolServer $server, \Closure|null $after = null): ControlledWriteConnection
    {
        return new ControlledWriteConnection(new Connection(new DnsSocketConnector(), new ConnectionConfiguration($server->host(), $server->port())), after: $after);
    }

    /** @return array{Client, WriteBufferService, SubscriptionStorage} */
    private function client(FakeNatsProtocolServer $server, ControlledWriteConnection $connection, Cancellation|null $cancellation = null): array
    {
        $this->logs = new CapturedProtocolLogs();
        $storage    = new SubscriptionStorage();
        $buffer     = new WriteBufferService(100, 100000);
        return [new Client(
            configuration: new ClientConfiguration(reconnectEnabled: true, maxReconnectAttempts: 5, reconnectBackoffInitialMs: 1, reconnectBackoffMaxMs: 1, reconnectJitterFraction: 0.0, bufferWhileReconnecting: true),
            cancellation: $cancellation ?? new NullCancellation(),
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, protocol: 1), $storage, $this->logs),
            storage: $storage,
            logger: $this->logs,
            writeBuffer: $buffer,
        ), $buffer, $storage];
    }

    private function reset(Client $client, int $timeout): Future
    {
        return async(static fn () => (new JetStreamAdmin(new JetStreamControlPlaneTransport($client)))->resetConsumer('S', 'C', 7, $timeout));
    }

    private function ready(Client $client, FakeNatsProtocolServer $server): void
    {
        $client->connect();
        $server->awaitCommandNameCount(0, 'PING', 1);
        $server->releasePong(0);
        $this->barrier($client, $server, 0);
    }

    private function barrier(Client $client, FakeNatsProtocolServer $server, int $session): void
    {
        $pubs   = count(array_filter($server->commandNames($session), static fn (string $name): bool => $name === 'PUB'));
        $future = async(static fn () => $client->request(new PubMessage('probe.next', 'control'), 1));
        $server->awaitCommandNameCount($session, 'PUB', $pubs + 1);
        $server->replyToLatestRequest($session, 'ok');
        self::assertSame('ok', $future->await()->getPayload());
        $this->forceTick();
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
            self::assertLessThan($deadline, hrtime(true));
            delay(0.001);
        }
    }

    /** @return list<array{command: string, payload: string}> */
    private function resets(FakeNatsProtocolServer $server): array
    {
        $resets = [];
        for ($index = 0; $index < 6; $index++) {
            array_push($resets, ...array_filter($server->publications($index), static fn (array $message): bool => str_starts_with($message['command'], self::RESET)));
        }
        return $resets;
    }

    private function assertFinal(FakeNatsProtocolServer $server, WriteBufferService $buffer, int $count): void
    {
        self::assertCount($count, $this->resets($server));
        foreach ($this->resets($server) as $message) {
            self::assertSame('{"seq":7}', $message['payload']);
        }
        self::assertSame(0, $buffer->getPendingMessages());
        self::assertSame(0, $buffer->getPendingBytes());
        self::assertFalse($buffer->hasFailedFrame());
    }

    /** @return list<string> */
    private function timers(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), static fn (string $id): bool => EventLoop::getType($id) === CallbackType::Delay));
    }

    private function trace(FakeNatsProtocolServer $server, string $name, int $count, bool $nextRequestSuccess = true): void
    {
        $directory = getenv('NATS_RESET_TRACE_DIR');
        if ($directory !== false && $directory !== '') {
            file_put_contents($directory . '/' . $name . '.json', json_encode(['resets' => $this->resets($server), 'reset_sends' => $count, 'next_request_success' => $nextRequestSuccess], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }
}
