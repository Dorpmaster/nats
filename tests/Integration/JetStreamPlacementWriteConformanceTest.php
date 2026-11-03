<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\Cancellation;
use Dorpmaster\Nats\Tests\Support\PlacementDiagnostics;
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
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamPlacementException;
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

final class JetStreamPlacementWriteConformanceTest extends TestCase
{
    use AsyncTestTools;

    private const string INFO    = '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","subjects":["s"],"metadata":{"_nats.level":"5"}}}';
    private const string CONFIG  = '{"type":"io.nats.jetstream.api.v1.stream_cancel_move_response","config":{"name":"S","subjects":["s"]}}';
    private const string COMMAND = 'PUB $JS.API.STREAM.CANCEL_MOVE.S ';
    private CapturedProtocolLogs $logs;

    public static function placementCommands(): iterable
    {
        yield ['cancel', 'STREAM.CANCEL_MOVE.S'];
        yield ['stream', 'STREAM.PEER.EVACUATE.S'];
        yield ['consumer', 'CONSUMER.PEER.EVACUATE.S.C'];
        yield ['server', 'SERVER.EVACUATE'];
    }

    #[DataProvider('placementCommands')]
    public function testUncertainDeliveredPlacementFailsOnceAndIsNeverReplayed(string $operation, string $suffix): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($operation, $suffix): void {
            $server            = new FakeNatsProtocolServer();
            $cause             = new ConnectionException('synthetic-uncertain-write-secret');
            $late              = null;
            $storage           = new SubscriptionStorage();
            $connection        = $this->connection($server, after: static function ($message, int $session) use ($server, $cause, $storage, &$late, $operation): void {
                if (self::isPlacement((string) $message)) {
                    $server->awaitCommandNameCount($session, 'PUB', $operation === 'server' ? 7 : 8);
                    $late = array_values($storage->all())[0];
                    throw $cause;
                }
            });
            [$client, $buffer] = $this->client($server, $connection, $storage);
            try {
                $this->ready($client, $server);
                $future = $this->command($client, $server, 1500, operation: $operation);
                try {
                    $future->await();
                    self::fail('Uncertain COMMAND cannot return success');
                } catch (JetStreamPlacementException $error) {
                    self::assertTrue($error->isOutcomeUnknown());
                    self::assertSame($cause, $error->getPrevious()->getPrevious()->getPrevious());
                }
                self::assertSame([], $storage->all());
                self::assertFalse($buffer->hasFailedFrame());
                self::assertCount(1, $this->commands($server));
                self::assertStringContainsString($suffix, $this->commands($server)[0]);
                $late(new MsgMessage('late', '1', self::CONFIG));
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                self::assertCount(1, $this->commands($server), 'No second COMMAND after the control exchange');
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
                if (self::isPlacement((string) $message)) {
                    $server->awaitCommandNameCount($session, 'PUB', 8);
                    $release->getFuture()->await();
                    throw new ConnectionException('synthetic-late-write-secret');
                }
            });
            [$client, $buffer] = $this->client($server, $connection, $storage);
            try {
                $this->ready($client, $server);
                $future = $this->command($client, $server, 1000);
                try {
                    $future->await();
                    self::fail('Expected deadline');
                } catch (JetStreamPlacementException $error) {
                    self::assertInstanceOf(CancelledException::class, $error->getPrevious()->getPrevious());
                    $primary = $error;
                }
                self::assertCount(1, $this->commands($server));
                self::assertSame([], $storage->all());
                $release->complete();
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                try {
                    $future->await();
                    self::fail('Original timeout must remain the result');
                } catch (JetStreamPlacementException $again) {
                    self::assertSame($primary, $again);
                }
                self::assertCount(1, $this->commands($server));
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
        yield 'paused timeout' => [false, false, false];
        yield 'paused cancellation' => [false, true, false];
        yield 'reconnect timeout before PONG' => [true, false, false];
        yield 'producer blocks event loop past deadline' => [false, false, true];
    }

    #[DataProvider('expiredQueue')]
    public function testExpiredUnsentPlacementAndInboxAreRemovedBeforeResume(bool $reconnect, bool $cancel, bool $block): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($reconnect, $cancel, $block): void {
            $server            = new FakeNatsProtocolServer();
            $storage           = new SubscriptionStorage();
            $source            = new DeferredCancellation();
            [$client, $buffer] = $this->client($server, $this->connection($server), $storage);
            try {
                $this->ready($client, $server);
                $future = $this->command($client, $server, 1000, $cancel ? $source->getCancellation() : null);
                $server->awaitCommandNameCount(0, 'PUB', 7);
                $infoSub = array_values(array_filter($server->rawCommands(0), static fn (string $line): bool => str_starts_with($line, 'SUB ')));
                $infoSid = substr($infoSub[array_key_last($infoSub)], strrpos($infoSub[array_key_last($infoSub)], ' ') + 1);
                $buffer->pause();
                $server->replyToLatestRequest(0, self::INFO);
                $this->waitFor(static fn (): bool => $buffer->getPendingMessages() === 3);
                $commandSid = array_key_first($storage->all());
                $late       = $storage->get($commandSid);
                if ($reconnect) {
                    $server->closeSession(0);
                    $session = $this->reconnectSession($server);
                } else {
                    $session = 0;
                }
                if ($block) {
                    usleep(1100000);
                    $buffer->resume();
                }
                if ($cancel) {
                    $source->cancel(new RuntimeException('synthetic-cancel-secret'));
                }
                try {
                    $future->await();
                    self::fail('Expired request must fail');
                } catch (JetStreamPlacementException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertInstanceOf(CancelledException::class, $error->getPrevious()->getPrevious());
                }
                self::assertSame([], $storage->all());
                self::assertFalse($buffer->hasFailedFrame());
                self::assertCount(0, $this->commands($server));
                // Only legitimate cleanup UNSUB commands remain; the request SUB and COMMAND are gone.
                self::assertSame($block ? 0 : ($reconnect ? 1 : 2), $buffer->getPendingMessages());
                self::assertSame($block ? 0 : strlen("UNSUB $infoSid\r\n") + ($reconnect ? 0 : strlen("UNSUB $commandSid\r\n")), $buffer->getPendingBytes());
                $late(new MsgMessage('late', '1', self::CONFIG));
                if ($reconnect) {
                    $server->releasePong($session);
                } else {
                    $buffer->resume();
                }
                $this->barrier($client, $server, $session);
                self::assertCount(0, $this->commands($server));
                $this->assertEmptyBuffer($buffer);
                for ($index = 0; $index < 6; $index++) {
                    $expiredSubs = array_filter($server->rawCommands($index), static fn (string $line): bool => str_starts_with($line, 'SUB ') && str_ends_with($line, ' ' . $commandSid));
                    self::assertSame([], array_values($expiredSubs), 'Expired request inbox must never be sent or replayed');
                }
                $this->trace($server, $buffer, $cancel ? 'cancelled_request' : 'unsent_timeout', true);
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
                $future = $this->command($client, $server, 1000);
                $server->awaitCommandNameCount(0, 'PUB', 8);
                $late = array_values($storage->all())[0];
                $server->replyToLatestRequest(0, self::CONFIG);
                self::assertSame('S', $future->await()->getName());
                $late(new MsgMessage('duplicate', '1', self::CONFIG));
                $this->forceTick();
                self::assertSame($timers, $this->timers());
                self::assertSame([], $storage->all());
                $this->barrier($client, $server, 0);
                self::assertCount(1, $this->commands($server));
                $this->assertEmptyBuffer($buffer);
                $this->trace($server, $buffer, 'confirmed', true);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public static function replayableMethods(): iterable
    {
        foreach (self::placementCommands() as [$operation, $suffix]) {
            $splits = [['ALT.API', $suffix]];
            foreach (['$JS.API', '$JS.EU.API'] as $namespace) {
                $tokens = explode('.', $namespace . '.' . $suffix);
                for ($index = 1; $index < count($tokens); $index++) {
                    $splits[] = [implode('.', array_slice($tokens, 0, $index)), implode('.', array_slice($tokens, $index))];
                }
            }
            foreach ($splits as $splitIndex => [$prefix, $tail]) {
                foreach (['request', 'requestJson', 'publishRequest'] as $method) {
                    yield 'split-' . $splitIndex . '/' . $prefix . '/' . $tail . '/' . $method => [$prefix, $tail, $method];
                }
            }
        }
    }
    #[DataProvider('replayableMethods')]
    public function testDirectReplayablePlacementIsRejectedBeforeAnyFrame(string $prefix, string $suffix, string $method): void
    {
        $this->setTimeout(8);
        $this->runAsyncTest(function () use ($prefix, $suffix, $method): void {
            $server            = new FakeNatsProtocolServer();
            $storage           = new SubscriptionStorage();
            [$client, $buffer] = $this->client($server, $this->connection($server), $storage);
            try {
                $this->ready($client, $server);
                $transport = new JetStreamControlPlaneTransport($client, apiPrefix: $prefix);
                try {
                    match ($method) {
                        'request' => $transport->request($suffix, [], 500),
                        'requestJson' => $transport->requestJson($suffix, new \Dorpmaster\Nats\Domain\JetStream\Model\JsonObject('{}'), 500),
                        'publishRequest' => $transport->publishRequest($suffix, [], 'REVIEW.INBOX'),
                    };
                    self::fail('Replayable placement methods must fail locally');
                } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $error) {
                    self::assertStringContainsString('connection-bound single attempt', $error->getDescription());
                }
                self::assertSame([], $storage->all());
                self::assertCount(1, $server->publications(0), 'Only the initial control exchange; no placement publication');
                self::assertCount(0, $this->commands($server));
                $this->assertEmptyBuffer($buffer);
                $server->closeSession(0);
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                self::assertCount(0, $this->commands($server));
                $this->trace($server, $buffer, 'direct_call_rejected', true);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }
    public static function observerKinds(): iterable
    {
        yield ['stream'];
        yield ['consumer'];
        yield ['rollback'];
    }
    #[DataProvider('observerKinds')]
    public function testObserverNeverPollsNewConnectionBetweenReplies(string $kind): void
    {
        $this->setTimeout(8);
        $this->runAsyncTest(function () use ($kind): void {
            $server            = new FakeNatsProtocolServer();
            [$client, $buffer] = $this->client($server, $this->connection($server), new SubscriptionStorage());
            try {
                $this->ready($client, $server);
                $admin      = new JetStreamAdmin(new JetStreamControlPlaneTransport($client));
                $raw        = new \Dorpmaster\Nats\Domain\JetStream\Model\JsonObject('{"type":"io.nats.jetstream.api.v1.stream_info_response","domain":"OLD","created":"2026-11-02T09:00:00.000000001Z","config":{"name":"S","num_replicas":1,"retention":"limits"},"cluster":{"raft_group":"R1","leader":"n1"}}');
                $original   = new \Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo('S', [], $raw->getObject('config'), $raw);
                $change     = $raw->withFields(['cluster' => ['raft_group' => 'R1', 'leader' => 'n1', 'desired' => ['origin' => ['replicas' => 1, 'retention' => 'limits']]]]);
                $transition = new \Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo('S', [], $change->getObject('config'), $change);
                $future     = async(static fn () => match ($kind) {
                    'stream' => $admin->waitForStreamEvacuation('S', 'n2', 1, 1800),
                    'consumer' => $admin->waitForConsumerEvacuation('S', 'C', 'n2', 1, 1800),
                    'rollback' => $admin->waitForStreamRollback($transition, $original, 1800),
                });
                $server->awaitCommandNameCount(0, 'PUB', 2);
                $reply = $kind === 'consumer' ? '{"type":"io.nats.jetstream.api.v1.consumer_info_response","domain":"OLD","stream_name":"S","name":"C","config":{"durable_name":"C"},"cluster":{"leader":"n2"}}' : str_replace('"leader":"n1"', '"leader":"n2"', $raw->toJson());
                $server->replyToLatestRequest(0, $reply);
                delay(.004);
                $server->closeSession(0);
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                try {
                    $future->await();
                    self::fail('Observation cannot change its connection');
                } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $error) {
                    self::assertStringContainsString('no longer current', $error->getDescription());
                }
                $this->barrier($client, $server, $session);
                $infos = array_filter($server->rawCommands($session), static fn (string $line): bool => preg_match('/\APUB \$JS\.(?:[^.]+\.)?API\.(?:STREAM|CONSUMER)\.INFO\./', $line) === 1);
                self::assertSame([], array_values($infos));
                $this->trace($server, $buffer, 'observer_connection_changed', true);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }
    public static function responseErrors(): iterable
    {
        foreach (['consumer_info_response', null, 'stream_cancel_move_response'] as $type) {
            foreach ([[400,10129],[408,null],[504,null]] as [$code, $err]) {
                yield [$type, $code, $err, $type !== 'stream_cancel_move_response' || $code !== 400];
            }
        }
    }
    #[DataProvider('responseErrors')]
    public function testErrorEnvelopeSurvivesTransportBeforeOutcomeClassification(string|null $type, int $code, int|null $err, bool $unknown): void
    {
        $this->setTimeout(8);
        $this->runAsyncTest(function () use ($type, $code, $err, $unknown): void {
            $server            = new FakeNatsProtocolServer();
            $storage           = new SubscriptionStorage();
            [$client, $buffer] = $this->client($server, $this->connection($server), $storage);
            try {
                $this->ready($client, $server);
                $future = $this->command($client, $server, 1500);
                $server->awaitCommandNameCount(0, 'PUB', 8);
                $response = ['error' => ['code' => $code, 'description' => 'synthetic rejection']];
                if ($err !== null) {
                    $response['error']['err_code'] = $err;
                }
                if ($type !== null) {
                    $response['type'] = 'io.nats.jetstream.api.v1.' . $type;
                }
                $server->replyToLatestRequest(0, json_encode($response, JSON_THROW_ON_ERROR));
                try {
                    $future->await();
                    self::fail('Expected controlled rejection');
                } catch (JetStreamPlacementException $error) {
                    self::assertSame($unknown, $error->isOutcomeUnknown());
                    self::assertSame($code, $error->getApiCode());
                    self::assertSame($err, $error->getErrorCode());
                    self::assertSame('synthetic rejection', $error->getDescription());
                    self::assertNotNull($error->getPrevious());
                }
                self::assertSame([], $storage->all());
                $this->barrier($client, $server, 0);
                self::assertCount(1, $this->commands($server));
                $this->assertEmptyBuffer($buffer);
                $this->trace($server, $buffer, $unknown ? 'unverified_response' : 'verified_rejection', true);
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

    private function command(Client $client, FakeNatsProtocolServer $server, int $timeout, Cancellation|null $cancellation = null, string $operation = 'cancel'): \Amp\Future
    {
        $admin  = new JetStreamAdmin(new JetStreamControlPlaneTransport($client));
        $future = async(static fn () => match ($operation) {
            'cancel' => $admin->cancelStreamMove('S', $timeout, $cancellation),
            'stream' => $admin->evacuateStreamPeer('S', 'n2', $timeout, $cancellation),
            'consumer' => $admin->evacuateConsumerPeer('S', 'C', 'n2', $timeout, $cancellation),
            'server' => $admin->evacuateServer(peer: 'n2', timeoutMs: $timeout, cancellation: $cancellation),
        });
        $server->awaitCommandNameCount(0, 'PUB', 2);
        $server->replyToLatestRequest(0, $operation === 'server' ? PlacementDiagnostics::response('$SYS.REQ.SERVER.fake.JSZ', 'fake') : '{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
        if ($operation === 'server') {
            $server->awaitCommandNameCount(0, 'PUB', 3);
            $server->replyToLatestRequest(0, PlacementDiagnostics::response('$SYS.REQ.SERVER.PING.JSZ', 'fake'));
        }
        for ($count = $operation === 'server' ? 4 : 3; $count <= 6; $count++) {
            $server->awaitCommandNameCount(0, 'PUB', $count);
            $publications = $server->publications(0);
            $subject      = explode(' ', $publications[array_key_last($publications)]['command'])[1];
            $response     = PlacementDiagnostics::response($subject, 'fake', account: json_decode($publications[array_key_last($publications)]['payload'], true)['account'] ?? ($operation === 'server' ? 'SYS' : 'A'));
            self::assertNotNull($response);
            $server->replyToLatestRequest(0, $response);
        }
        if ($operation !== 'server') {
            $server->awaitCommandNameCount(0, 'PUB', 7);
            $server->replyToLatestRequest(0, $operation === 'consumer' ? '{"type":"io.nats.jetstream.api.v1.consumer_info_response","stream_name":"S","name":"C","config":{"durable_name":"C","metadata":{"_nats.level":"5"}}}' : self::INFO);
        }
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
    private function commands(FakeNatsProtocolServer $server): array
    {
        $commands = [];
        for ($index = 0; $index < 6; $index++) {
            array_push($commands, ...array_filter($server->rawCommands($index), static fn (string $line): bool => self::isPlacement($line)));
        }
        return $commands;
    }

    private static function isPlacement(string $frame): bool
    {
        return preg_match('/\APUB \$JS\.(?:[^.]+\.)?API\.(?:STREAM\.(?:CANCEL_MOVE|PEER\.EVACUATE)\.|CONSUMER\.PEER\.EVACUATE\.|SERVER\.EVACUATE )/', $frame) === 1;
    }

    private function assertEmptyBuffer(WriteBufferService $buffer): void
    {
        self::assertSame(0, $buffer->getPendingMessages());
        self::assertSame(0, $buffer->getPendingBytes());
        self::assertFalse($buffer->hasFailedFrame());
    }

    private function trace(FakeNatsProtocolServer $server, WriteBufferService $buffer, string $result, bool $nextRequest): void
    {
        $directory = getenv('NATS_PLACEMENT_TRACE_DIR');
        if ($directory === false || $directory === '') {
            return;
        }
        $connections = [];
        for ($index = 0; $index < 6; $index++) {
            $commands = $server->commandNames($index);
            if ($commands !== []) {
                $connections[$index] = [
                    'commands' => $commands,
                    'placement_sends' => count(array_filter($server->rawCommands($index), static fn (string $line): bool => self::isPlacement($line))),
                    'placement_frames' => array_values(array_filter($server->publications($index), static fn (array $publication): bool => self::isPlacement($publication['command']))),
                ];
            }
        }
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->nameWithDataSet());
        file_put_contents($directory . '/' . $name . '.json', json_encode([
            'result' => $result,
            'command_sends' => count($this->commands($server)),
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
