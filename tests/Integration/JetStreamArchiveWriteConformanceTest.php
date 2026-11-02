<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\{Cancellation,DeferredCancellation,NullCancellation};
use Amp\ByteStream\{ReadableBuffer,WritableBuffer};
use Amp\Socket\DnsSocketConnector;
use Dorpmaster\Nats\Client\{Client,ClientConfiguration,MessageDispatcher,SubscriptionStorage,WriteBufferService};
use Dorpmaster\Nats\Connection\{Connection,ConnectionConfiguration};
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Backup\{BackupOptions,StreamBackupMetadata};
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamArchiveException;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\{AsyncTestTools,CapturedProtocolLogs,ControlledWriteConnection,FakeNatsProtocolServer};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class JetStreamArchiveWriteConformanceTest extends TestCase
{
    use AsyncTestTools;

    private const string SNAPSHOT = '{"type":"io.nats.jetstream.api.v1.stream_snapshot_response","config":{"name":"S","subjects":["s"],"storage":"file"},"state":{"messages":1,"bytes":3,"first_seq":1,"last_seq":1,"consumer_count":0}}';
    private CapturedProtocolLogs $logs;
    private static function metadata(): StreamBackupMetadata
    {
        return StreamBackupMetadata::fromJson('{"type":"dorpmaster.nats.backup.v2","stream":"S","archive_bytes":3,"config":{"name":"S","subjects":["s"],"storage":"file"},"state":{"messages":1,"bytes":3,"first_seq":1,"last_seq":1,"consumer_count":0}}');
    }
    public static function stages(): iterable
    {
        yield['backup_start'];
        yield['restore_start'];
        yield['chunk'];
        yield['final'];
        yield['ack'];
    }
    #[DataProvider('stages')]
    public function testFullyReadFrameFailureReconnectNeverReplaysOldArchive(string $stage): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($stage): void {
            $server           = new FakeNatsProtocolServer();
            $storage          = new SubscriptionStorage();
            $cause            = new ConnectionException('synthetic-n9-write-secret');
            $faulted          = false;
            $connection       = $this->connection($server, after: function ($message, int $session) use ($server, $stage, $cause, &$faulted): void {
                $frame = (string)$message;
                $match = match ($stage) {
                    'backup_start'=>str_contains($frame, ' STREAM.SNAPSHOT.') || str_starts_with($frame, 'HPUB $JS.API.STREAM.SNAPSHOT.'),
                    'restore_start'=>str_starts_with($frame, 'HPUB $JS.API.STREAM.RESTORE.'),
                    'chunk'=>str_starts_with($frame, 'PUB $JS.SNAPSHOT.RESTORE.') && $message->getPayload() !== '',
                    'final'=>str_starts_with($frame, 'PUB $JS.SNAPSHOT.RESTORE.') && $message->getPayload() === '',
                    'ack'=>str_starts_with($frame, 'PUB $JS.SNAPSHOT.ACK.'),
                };
                if ($session !== 0 || $faulted || !$match) {
                    return;
                }
                // The peer has parsed the complete payload and its CRLF before failure is injected.
                $this->waitFor(static fn(): bool=>count(array_filter($server->publications(0), static fn($p)=>$p['command'] === strtok($frame, "\r\n"))) > 0);
                $faulted = true;
                throw $cause;
            });
            [$client,$buffer] = $this->client($server, $connection, $storage);
            try {
                $this->ready($client, $server);
                $admin  = new JetStreamAdmin(new JetStreamControlPlaneTransport($client));
                $backup = str_starts_with($stage, 'backup') || $stage === 'ack';
                $future = async(static fn()=> $backup ? $admin->backupStream('S', new WritableBuffer(), new BackupOptions(chunkSize:1024, windowSize:1024), 2000) : $admin->restoreStream('D', new ReadableBuffer('abc'), self::metadata(), 'memory', timeoutMs:2000));
                $server->awaitCommandNameCount(0, 'HPUB', 1);
                $server->replyToLatestRequest(0, '{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
                $server->awaitCommandNameCount(0, 'HPUB', 2);
                if (!str_ends_with($stage, 'start')) {
                    if ($backup) {
                        $body    = self::body($server->publications(0)[array_key_last($server->publications(0))]);
                        $deliver = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['deliver_subject'];
                        $sub     = array_values(array_filter($server->rawCommands(0), static fn($c)=>str_starts_with($c, 'SUB ' . $deliver . ' ')))[0];
                        $sid     = explode(' ', $sub)[2];
                        $server->replyToLatestRequest(0, self::SNAPSHOT);
                        // Send the full chunk with flow-control reply through the real parser.
                        $server->sendArchiveMessage(0, $deliver, $sid, str_repeat('x', 1024), '$JS.SNAPSHOT.ACK.S.token.1024.1');
                    } else {
                        $server->replyToLatestRequest(0, '{"type":"io.nats.jetstream.api.v1.stream_restore_response","deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"}');
                        $server->awaitCommandNameCount(0, 'PUB', 2); // includes initial control barrier
                        if ($stage === 'final') {
                            $server->replyToLatestRequest(0, '');
                        }
                    }
                }
                try {
                    $future->await();
                    self::fail('Uncertain archive write must fail');
                } catch (JetStreamArchiveException $error) {
                    self::assertSame(!$backup, $error->isOutcomeUnknown());
                    self::assertSame($cause, $this->rootCause($error));
                }
                self::assertTrue($faulted);
                self::assertSame([], $storage->all());
                self::assertFalse($buffer->hasFailedFrame());
                $session = $this->reconnectSession($server);
                $server->releasePong($session);
                $this->barrier($client, $server, $session);
                self::assertSame([], array_values(array_filter($server->publications($session), static fn($p)=>str_contains($p['command'], '$JS.'))));
                self::assertSame(0, $buffer->getPendingMessages());
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame([], $storage->all());
                $this->trace($stage, ['session_0' => $server->rawCommands(0),'session_1' => $server->rawCommands($session),'outcome_unknown' => !$backup,'pending_bytes' => $buffer->getPendingBytes(),'control_request' => 'ok']);
            } finally {
                $client->disconnect();
                $server->stop();
                $this->logs->assertSafe(['synthetic-n9-write-secret',str_repeat('x', 1024)]);
            }
        });
    }
    public static function queued(): iterable
    {
        foreach (['backup_start','restore_start','ack','chunk','final'] as $stage) {
            yield[$stage,true];
            yield[$stage,false];
        }
    }
    #[DataProvider('queued')]
    public function testCancellationOrDeadlineWithdrawsQueuedStartAckChunksAndEof(string $stage, bool $cancel): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($stage, $cancel): void {
            $server           = new FakeNatsProtocolServer();
            $storage          = new SubscriptionStorage();
            $source           = new DeferredCancellation();
            [$client,$buffer] = $this->client($server, $this->connection($server), $storage);
            try {
                $this->ready($client, $server);
                $admin  = new JetStreamAdmin(new JetStreamControlPlaneTransport($client));
                $backup = str_starts_with($stage, 'backup') || $stage === 'ack';
                $future = async(static fn()=> $backup ? $admin->backupStream('S', new WritableBuffer(), new BackupOptions(chunkSize:1024, windowSize:1024), 250, $source->getCancellation()) : $admin->restoreStream('D', new ReadableBuffer('abc'), self::metadata(), 'memory', timeoutMs:250, cancellation:$source->getCancellation()));
                $server->awaitCommandNameCount(0, 'HPUB', 1);
                if (str_ends_with($stage, 'start')) {
                    $buffer->pause();
                }
                $server->replyToLatestRequest(0, '{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
                if (!str_ends_with($stage, 'start')) {
                    $server->awaitCommandNameCount(0, 'HPUB', 2);
                    if ($backup) {
                        $body    = self::body($server->publications(0)[array_key_last($server->publications(0))]);
                        $deliver = json_decode($body, true)['deliver_subject'];
                        $sub     = array_values(array_filter($server->rawCommands(0), static fn($c)=>str_starts_with($c, 'SUB ' . $deliver . ' ')))[0];
                        $sid     = explode(' ', $sub)[2];
                        $server->replyToLatestRequest(0, self::SNAPSHOT);
                        $buffer->pause();
                        $server->sendArchiveMessage(0, $deliver, $sid, str_repeat('x', 1024), '$JS.SNAPSHOT.ACK.S.token.1024.1');
                    } else {
                        if ($stage === 'chunk') {
                            $buffer->pause();
                        }
                        $server->replyToLatestRequest(0, '{"type":"io.nats.jetstream.api.v1.stream_restore_response","deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"}');
                        if ($stage === 'final') {
                            $server->awaitCommandNameCount(0, 'PUB', 2);
                            $buffer->pause();
                            $server->replyToLatestRequest(0, '');
                        }
                    }
                }
                $this->waitFor(static fn()=> $buffer->getPendingMessages() > 0);
                $before = count($server->publications(0));
                if ($cancel) {
                    $source->cancel();
                } else {
                    usleep(300000); // Blocked producer: physical attempt must also recheck time.
                }
                try {
                    $future->await();
                    self::fail('Cancelled/expired queue cannot succeed');
                } catch (JetStreamArchiveException $error) {
                    self::assertFalse($backup ? $error->isOutcomeUnknown() : str_ends_with($stage, 'start') && $error->isOutcomeUnknown());
                }
                self::assertSame([], $storage->all());
                self::assertFalse($buffer->hasFailedFrame());
                $buffer->resume();
                $this->barrier($client, $server, 0);
                self::assertCount($before + 1, $server->publications(0));
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame(0, $buffer->getPendingMessages());
                $this->trace($stage . '-' . ($cancel ? 'cancel' : 'deadline'), ['commands' => $server->rawCommands(0),'old_frames_after_resume' => 0,'control_request' => 'ok']);
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testSuccessfulBackupReleasesTimersAndInboxAndIgnoresLateData(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server           = new FakeNatsProtocolServer();
            $storage          = new SubscriptionStorage();
            [$client,$buffer] = $this->client($server, $this->connection($server), $storage);
            try {
                $this->ready($client, $server);
                $timers = $this->timers();
                $sink   = new WritableBuffer();
                $admin  = new JetStreamAdmin(new JetStreamControlPlaneTransport($client));
                $future = async(static fn()=>$admin->backupStream('S', $sink, timeoutMs:1500));
                $server->awaitCommandNameCount(0, 'HPUB', 1);
                $server->replyToLatestRequest(0, '{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
                $server->awaitCommandNameCount(0, 'HPUB', 2);
                $body    = self::body($server->publications(0)[array_key_last($server->publications(0))]);
                $deliver = json_decode($body, true)['deliver_subject'];
                $sub     = array_values(array_filter($server->rawCommands(0), static fn($c)=>str_starts_with($c, 'SUB ' . $deliver . ' ')))[0];
                $sid     = explode(' ', $sub)[2];
                $late    = $storage->get($sid);
                $server->replyToLatestRequest(0, self::SNAPSHOT);
                $server->sendArchiveMessage(0, $deliver, $sid, 'abc');
                $server->sendArchiveMessage(0, $deliver, $sid, '');
                $metadata = $future->await();
                self::assertSame(3, $metadata->archiveBytes);
                $late(new \Dorpmaster\Nats\Protocol\MsgMessage($deliver, $sid, 'late'));
                $this->barrier($client, $server, 0);
                self::assertSame($timers, $this->timers());
                self::assertSame([], $storage->all());
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertFalse($buffer->hasFailedFrame());
                $sink->end();
                self::assertSame('abc', $sink->buffer());
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }
    private function timers(): array
    {
        return array_values(array_filter(\Revolt\EventLoop::getIdentifiers(), static fn(string $id): bool=>\Revolt\EventLoop::getType($id) === \Revolt\EventLoop\CallbackType::Delay));
    }

    public static function abortStatuses(): iterable
    {
        yield[408];
        yield[500];
        yield[0];
    }
    #[DataProvider('abortStatuses')]
    public function testBackupServerErrorOrMissingFinalIsNotCompletedArchive(int $status): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($status): void {
            $server           = new FakeNatsProtocolServer();
            $storage          = new SubscriptionStorage();
            [$client,$buffer] = $this->client($server, $this->connection($server), $storage);
            try {
                $this->ready($client, $server);
                $timers = $this->timers();
                $sink   = new WritableBuffer();
                $admin  = new JetStreamAdmin(new JetStreamControlPlaneTransport($client));
                $future = async(static fn()=>$admin->backupStream('S', $sink, timeoutMs:200));
                $server->awaitCommandNameCount(0, 'HPUB', 1);
                $server->replyToLatestRequest(0, '{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
                $server->awaitCommandNameCount(0, 'HPUB', 2);
                $body    = self::body($server->publications(0)[array_key_last($server->publications(0))]);
                $deliver = json_decode($body, true)['deliver_subject'];
                $sub     = array_values(array_filter($server->rawCommands(0), static fn($c)=>str_starts_with($c, 'SUB ' . $deliver . ' ')))[0];
                $sid     = explode(' ', $sub)[2];
                $server->replyToLatestRequest(0, self::SNAPSHOT);
                $server->sendArchiveMessage(0, $deliver, $sid, 'abc');
                if ($status !== 0) {
                    $server->sendArchiveStatus(0, $deliver, $sid, $status);
                }
                try {
                    $future->await();
                    self::fail('No completed backup');
                } catch (JetStreamArchiveException $error) {
                    self::assertSame($status, $error->getApiCode());
                    self::assertSame(3, $error->getTransferredBytes());
                    self::assertTrue($error->mayHavePartialOutput());
                    self::assertFalse($error->isOutcomeUnknown());
                }
                $this->barrier($client, $server, 0);
                self::assertSame([], $storage->all());
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame($timers, $this->timers());
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testProtocolCallbackFailureFailsOnlyItsChannelAndReleasesInbox(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server           = new FakeNatsProtocolServer();
            $storage          = new SubscriptionStorage();
            [$client,$buffer] = $this->client($server, $this->connection($server), $storage);
            $cause            = new \RuntimeException('synthetic protocol callback secret');
            $failures         = [];
            try {
                $this->ready($client, $server);
                $timers  = $this->timers();
                $channel = $client->openProtocolChannelOnConnection('proto.private', static function () use ($cause): void {
                    throw $cause;
                }, static function ($error) use (&$failures): void {
                    $failures[] = $error;
                }, $client->getServerInformation()->connectionId);
                $server->awaitCommandNameCount(0, 'SUB', 2);
                $subscription = array_values(array_filter($server->rawCommands(0), static fn($line)=>str_starts_with($line, 'SUB proto.private ')))[0];
                $sid          = explode(' ', $subscription)[2];
                $server->sendArchiveMessage(0, 'proto.private', $sid, 'private');
                $this->waitFor(static function () use (&$failures): bool {
                    return count($failures) === 1;
                });
                self::assertSame($cause, $failures[0]);
                self::assertSame([], $storage->all());
                $server->sendArchiveMessage(0, 'proto.private', $sid, 'late');
                $this->barrier($client, $server, 0);
                self::assertCount(1, $failures);
                self::assertSame([], $storage->all());
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame($timers, $this->timers());
                $channel->close();
            } finally {
                $client->disconnect();
                $server->stop();
                $this->logs->assertSafe(['synthetic protocol callback secret']);
            }
        });
    }
    private static function body(array $publication): string
    {
        return str_starts_with($publication['command'], 'HPUB ') ? explode("\r\n\r\n", $publication['payload'], 2)[1] : $publication['payload'];
    }
    private function rootCause(\Throwable $error): \Throwable
    {
        while ($error->getPrevious() !== null) {
            $error = $error->getPrevious();
        }return $error;
    }
    private function trace(string $name, array $data): void
    {
        if ($path = getenv('NATS_ARCHIVE_TRACE_DIR')) {
            file_put_contents($path . '/tcp-' . $name . '.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
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
}
