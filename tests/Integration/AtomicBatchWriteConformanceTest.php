<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\DeferredCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Client\WriteBufferService;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Dorpmaster\Nats\Domain\Client\RequestWriteException;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\JetStream\Exception\AtomicBatchException;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
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

final class AtomicBatchWriteConformanceTest extends TestCase
{
    use AsyncTestTools;

    private CapturedProtocolLogs $logs;

    public static function lostCommitKinds(): iterable
    {
        foreach ([1, 3] as $count) {
            yield 'lost reply ' . $count => [false, false, $count];
            yield 'uncertain write ' . $count => [true, false, $count];
            yield 'reply timeout ' . $count => [false, true, $count];
        }
    }

    #[DataProvider('lostCommitKinds')]
    public function testCommitWasReceivedOnceAndNeverReplayed(bool $writeThrows, bool $timeout, int $count): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($writeThrows, $timeout, $count): void {
            $server                      = new FakeNatsProtocolServer();
            $cause                       = new ConnectionException('synthetic-atomic-write-secret');
            $after                       = $writeThrows ? static function ($message, int $session) use ($server, $cause, $count): void {
                if ($message->getType()->value === 'HPUB' && str_contains((string) $message, "Nats-Batch-Commit: 1\r\n")) {
                    $server->awaitCommandNameCount($session, 'HPUB', $count);
                    throw $cause;
                }
            } : null;
            [$client, $buffer, $storage] = $this->client($server, $after);
            try {
                $this->ready($client, $server);
                $future = async(fn () => $this->publish($client, $count, $timeout ? 500 : 2000));
                for ($index = 1; $index < $count; $index++) {
                    $server->awaitCommandNameCount(0, 'HPUB', $index);
                    $server->replyToLatestRequest(0, '');
                }
                $server->awaitCommandNameCount(0, 'HPUB', $count);
                $id = $this->header($this->frames($server)[0]['payload'], 'Nats-Batch-Id');
                self::assertSame('1', $this->header($this->frames($server)[$count - 1]['payload'], 'Nats-Batch-Commit'));
                if (!$writeThrows && !$timeout) {
                    $server->closeSession(0);
                }
                try {
                    $future->await();
                    self::fail('Commit without reliable reply is uncertain');
                } catch (AtomicBatchException $error) {
                    self::assertTrue($error->isOutcomeUnknown());
                    self::assertSame($id, $error->getBatchId());
                    self::assertStringContainsString($id, $error->getMessage());
                    self::assertInstanceOf(ConnectionBoundRequestException::class, $error->getPrevious());
                    self::assertTrue($error->getPrevious()->wasAttempted());
                    if ($writeThrows) {
                        self::assertInstanceOf(RequestWriteException::class, $error->getPrevious()->getPrevious());
                        self::assertSame($cause, $error->getPrevious()->getPrevious()->getPrevious());
                    }
                }
                self::assertSame([], $storage->all());
                if ($timeout) {
                    $server->closeSession(0);
                }
                $session = $this->reconnected($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                $this->emptyBuffer($buffer);
                self::assertCount($count, $this->frames($server));
                $this->trace(($writeThrows ? 'uncertain-write' : ($timeout ? 'commit-reply-timeout' : 'lost-commit-reply')) . '-' . $count, $server, ['user_messages' => $count, 'commit_count' => 1, 'post_reconnect_frames' => 0, 'outcome_unknown' => true, 'next_request_success' => true]);
            } finally {
                $client->disconnect();
                $server->stop();
                $this->logs->assertSafe(['synthetic-atomic-write-secret', 'synthetic-atomic-payload']);
            }
        });
    }

    public static function pausedKinds(): iterable
    {
        yield 'timeout' => ['timeout'];
        yield 'disconnect' => ['disconnect'];
        yield 'operation cancel' => ['cancel'];
    }

    #[DataProvider('pausedKinds')]
    public function testUnsentCommitIsWithdrawnAndNotUncertain(string $kind): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($kind): void {
            $server                      = new FakeNatsProtocolServer();
            $buffer                      = null;
            [$client, $buffer, $storage] = $this->client($server, static function ($message) use (&$buffer): void {
                if (str_starts_with((string) $message, 'PUB $SYS.REQ.SERVER.fake.ROUTEZ ')) {
                    $buffer->pause();
                }
            });
            $source                      = new DeferredCancellation();
            try {
                $this->ready($client, $server);
                $started = hrtime(true);
                $future  = async(fn () => $this->publish($client, 1, $kind === 'timeout' ? 500 : 5000, new AtomicBatchOptions(cancellation: $source->getCancellation())));
                $this->waitFor(static fn (): bool => $buffer->getPendingMessages() >= 2 && count($storage->all()) === 1);
                $sid  = array_key_first($storage->all());
                $late = $storage->get($sid);
                if ($kind === 'disconnect') {
                    $server->closeSession(0);
                }
                if ($kind === 'cancel') {
                    $source->cancel();
                }
                try {
                    $future->await();
                    self::fail('Expired, cancelled or disconnected unsent commit cannot proceed');
                } catch (AtomicBatchException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertFalse($error->getPrevious()->wasAttempted());
                }
                self::assertLessThan(1.5, (hrtime(true) - $started) / 1_000_000_000, 'Disconnect must stop the request without waiting for its 5 second deadline');
                self::assertSame([], $storage->all());
                $late(new MsgMessage('late', '1', '{"seq":1}'));
                $session = 0;
                if ($kind === 'disconnect') {
                    $session = $this->reconnected($server);
                    $server->releasePong($session);
                } else {
                    $buffer->resume();
                }
                $this->barrier($client, $server, $session);
                self::assertSame([], $this->frames($server));
                $this->emptyBuffer($buffer);
                foreach ([0, $session] as $index) {
                    self::assertSame([], array_values(array_filter($server->rawCommands($index), static fn (string $line): bool => str_starts_with($line, 'SUB ') && str_ends_with($line, ' ' . $sid))));
                }
                $this->trace('unsent-' . $kind, $server, ['commit_count' => 0, 'outcome_unknown' => false, 'next_request_success' => true]);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testReconnectCannotContinueQueuedStagingOrCommit(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server                      = new FakeNatsProtocolServer();
            $buffer                      = null;
            [$client, $buffer, $storage] = $this->client($server, static function ($message) use (&$buffer): void {
                if ($message->getType()->value === 'HPUB') {
                    $buffer->pause();
                }
            });
            try {
                $this->ready($client, $server);
                $future = async(fn () => $this->publish($client, 3));
                $server->awaitCommandNameCount(0, 'HPUB', 1);
                $server->replyToLatestRequest(0, '');
                $this->waitFor(static fn (): bool => $buffer->getPendingMessages() >= 3);
                $server->closeSession(0);
                try {
                    $future->await();
                    self::fail('Connection change must abandon the batch');
                } catch (AtomicBatchException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                }
                $session = $this->reconnected($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                self::assertCount(1, $this->frames($server));
                self::assertNull($this->header($this->frames($server)[0]['payload'], 'Nats-Batch-Commit'));
                self::assertSame([], $storage->all());
                $this->emptyBuffer($buffer);
                $this->trace('staging-disconnect', $server, ['staged_frames' => 1, 'commit_count' => 0, 'post_reconnect_frames' => 0, 'next_request_success' => true]);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testSuccessReleasesTimerAndIgnoresLateCallbackAndAsyncInfo(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server                      = new FakeNatsProtocolServer();
            [$client, $buffer, $storage] = $this->client($server);
            try {
                $this->ready($client, $server);
                $timers = $this->timers();
                $future = async(fn () => $this->publish($client, 2));
                $server->awaitCommandNameCount(0, 'HPUB', 1);
                $server->sendInfo(0, ['api_lvl' => 5, 'jetstream' => true]);
                $server->replyToLatestRequest(0, '');
                $server->awaitCommandNameCount(0, 'HPUB', 2);
                $id   = $this->header($this->frames($server)[1]['payload'], 'Nats-Batch-Id');
                $late = array_values($storage->all())[0];
                $server->replyToLatestRequest(0, '{"stream":"S","batch":"' . $id . '","count":2,"seq":2}');
                $ack = $future->await();
                $late(new MsgMessage('late', '1', '{"seq":99}'));
                $this->forceTick();
                self::assertSame('2', $ack->getSeq());
                self::assertSame($timers, $this->timers());
                self::assertSame([], $storage->all());
                $this->barrier($client, $server, 0);
                $this->emptyBuffer($buffer);
                self::assertCount(2, $this->frames($server));
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    private function client(FakeNatsProtocolServer $server, \Closure|null $after = null): array
    {
        $this->logs = new CapturedProtocolLogs();
        $storage    = new SubscriptionStorage();
        $buffer     = new WriteBufferService(100, 100000);
        $connection = new ControlledWriteConnection(new Connection(new \Amp\Socket\DnsSocketConnector(), new ConnectionConfiguration($server->host(), $server->port())), after: function ($message, int $session) use ($server, $after): void {
            if (str_starts_with((string) $message, 'PUB $JS.API.STREAM.') || str_starts_with((string) $message, 'PUB $SYS.REQ.')) {
                $command = explode("\r\n", (string) $message, 2)[0];
                $this->waitFor(static fn (): bool => count(array_filter($server->publications($session), static fn ($record): bool => $record['command'] === $command)) === 1);
                $payload = \Dorpmaster\Nats\Tests\Support\AtomicBatchTopologyResponses::payload(explode(' ', $command)[1], 'fake') ?? (str_starts_with((string) $message, 'PUB $JS.API.STREAM.INFO.')
                    ? '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_atomic":true,"metadata":{"_nats.level":"5"}}}'
                    : '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":1,"streams":["S"]}');
                $server->replyToLatestRequest($session, $payload);
            }
            $after?->__invoke($message, $session);
        });
        $client     = new Client(new ClientConfiguration(reconnectEnabled: true, maxReconnectAttempts: 5, reconnectBackoffInitialMs: 1, reconnectBackoffMaxMs: 1, reconnectJitterFraction: 0.0, bufferWhileReconnecting: true), new \Amp\NullCancellation(), $connection, new EventDispatcher(), new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, headers: true, protocol: 1), $storage, $this->logs), $storage, $this->logs, writeBuffer: $buffer);
        return [$client, $buffer, $storage];
    }

    private function ready(Client $client, FakeNatsProtocolServer $server): void
    {
        $client->connect();
        $server->awaitCommandNameCount(0, 'PING', 1);
        $server->releasePong(0);
        $this->barrier($client, $server, 0);
        $server->sendInfo(0, ['api_lvl' => 5, 'jetstream' => true]);
        $this->waitFor(static fn (): bool => $client->getServerInformation()->apiLevel === 5);
    }

    private function publish(Client $client, int $count = 1, int $timeout = 2000, AtomicBatchOptions|null $options = null): \Dorpmaster\Nats\Domain\JetStream\Model\AtomicBatchAck
    {
        return (new JetStreamPublisher($client, $this->logs))->publishAtomicBatch('S', array_fill(0, $count, ['subject' => 'atomic.data', 'payload' => 'synthetic-atomic-payload']), $options, $timeout);
    }

    private function barrier(Client $client, FakeNatsProtocolServer $server, int $session): void
    {
        $pubs   = count(array_filter($server->commandNames($session), static fn ($value): bool => $value === 'PUB'));
        $future = async(static fn () => $client->request(new PubMessage('probe.next', 'control'), 1));
        $server->awaitCommandNameCount($session, 'PUB', $pubs + 1);
        $server->replyToLatestRequest($session, 'ok');
        self::assertSame('ok', $future->await()->getPayload());
        $this->forceTick();
    }

    private function reconnected(FakeNatsProtocolServer $server): int
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

    private function frames(FakeNatsProtocolServer $server): array
    {
        $result = [];
        for ($index = 0; $index < 6; $index++) {
            foreach ($server->publications($index) as $record) {
                if (str_starts_with($record['command'], 'HPUB ')) {
                    $result[] = $record + ['session' => $index];
                }
            }
        }
        return $result;
    }

    private function header(string $body, string $name): string|null
    {
        foreach (explode("\r\n", explode("\r\n\r\n", $body, 2)[0]) as $line) {
            if (str_starts_with($line, $name . ': ')) {
                return substr($line, strlen($name) + 2);
            }
        }
        return null;
    }

    private function waitFor(\Closure $predicate): void
    {
        $deadline = hrtime(true) + 2_000_000_000;
        while (!$predicate()) {
            self::assertLessThan($deadline, hrtime(true));
            delay(0.001);
        }
    }

    private function emptyBuffer(WriteBufferService $buffer): void
    {
        self::assertSame(0, $buffer->getPendingBytes());
        self::assertSame(0, $buffer->getPendingMessages());
        self::assertFalse($buffer->hasFailedFrame());
    }

    private function timers(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), static fn ($id): bool => EventLoop::getType($id) === CallbackType::Delay));
    }

    private function trace(string $name, FakeNatsProtocolServer $server, array $observations): void
    {
        $directory = getenv('NATS_ATOMIC_TRACE_DIR');
        if ($directory !== false && $directory !== '') {
            $frames = array_map(static fn ($frame) => ['command' => $frame['command'], 'body_base64' => base64_encode($frame['payload']), 'session' => $frame['session']], $this->frames($server));
            file_put_contents($directory . '/' . $name . '.json', json_encode(['frames' => $frames, 'observations' => $observations], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }
}
