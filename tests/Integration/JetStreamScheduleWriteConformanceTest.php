<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\WriteBufferService;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Domain\JetStream\Publish\{JetStreamPublisher,PublishOptions,ScheduleOptions,MessageSchedule};
use Dorpmaster\Nats\Domain\JetStream\Exception\ScheduledPublishException;
use Dorpmaster\Nats\Tests\Support\{FakeNatsProtocolServer,ControlledWriteConnection,AtomicBatchTargetStubClient,AsyncTestTools,CapturedProtocolLogs};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class JetStreamScheduleWriteConformanceTest extends TestCase
{
    use AsyncTestTools;

    private function setupClient(FakeNatsProtocolServer $server, \Closure $after): array
    {
        $storage    = new SubscriptionStorage();
        $logs       = new CapturedProtocolLogs();
        $buffer     = new WriteBufferService(100, 1000000, logger:$logs);
        $connection = new ControlledWriteConnection(new Connection(new \Amp\Socket\DnsSocketConnector(), new ConnectionConfiguration($server->host(), $server->port())), after:$after);
        $client     = new Client(new ClientConfiguration(reconnectEnabled:true, maxReconnectAttempts:5, reconnectBackoffInitialMs:1, reconnectBackoffMaxMs:1, reconnectJitterFraction:0.0, bufferWhileReconnecting:true), new \Amp\NullCancellation(), $connection, new EventDispatcher(), new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, headers:true, protocol:1), $storage, $logs), $storage, $logs, writeBuffer:$buffer);
        $client->connect();
        $this->ready($client, $server, 0);
        return [$client,$buffer,$storage,$logs];
    }
    private function ready(Client $client, FakeNatsProtocolServer $server, int $session): void
    {
        $server->awaitCommandNameCount($session, 'PING', 1);
        $server->sendInfo($session, ['api_lvl' => 5,'jetstream' => true,'version' => '2.15.2']);
        $server->releasePong($session);
        $this->barrier($client, $server, $session);
    }
    private function barrier(Client $client, FakeNatsProtocolServer $server, int $session): void
    {
        $count  = count(array_filter($server->commandNames($session), static fn($name)=>$name === 'PUB'));
        $future = async(fn()=>$client->request(new PubMessage('probe.next', 'ok'), 1));
        $server->awaitCommandNameCount($session, 'PUB', $count + 1);
        $server->replyToLatestRequest($session, 'ok');
        self::assertSame('ok', $future->await()->getPayload());
        delay(.001);
    }
    private function publisher(Client $client): JetStreamPublisher
    {
        return new JetStreamPublisher(new AtomicBatchTargetStubClient($client, static function ($message) {
            if (str_contains($message->getSubject(), '.INFO.')) {
                return '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_msg_schedules":true,"metadata":{"_nats.level":"5"}}}';
            }
            if (str_contains($message->getSubject(), '.NAMES')) {
                return '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":1,"streams":["S"]}';
            }
            return null;
        }));
    }
    private function options(): PublishOptions
    {
        return new PublishOptions(schedule:new ScheduleOptions('S', 'target', MessageSchedule::at('2099-01-01T00:00:00Z')));
    }
    private function frames(FakeNatsProtocolServer $server, int $session): array
    {
        return array_values(array_filter($server->publications($session), static fn($f)=>str_starts_with($f['command'], 'HPUB ')));
    }
    private function wait(\Closure $predicate): void
    {
        $limit = hrtime(true) + 3000000000;
        while (!$predicate()) {
            if (hrtime(true) >= $limit) {
                throw new \RuntimeException('Schedule TCP barrier timed out');
            }delay(.001);
        }
    }
    public static function losses(): iterable
    {
        yield ['eof'];
        yield ['write'];
    }
    #[DataProvider('losses')]
    public function testDefinitionIsAttemptedOnceAcrossReconnect(string $kind): void
    {
        $this->setTimeout(15);$this->runAsyncTest(function () use ($kind): void {
            $server                          = new FakeNatsProtocolServer();
            $cause                           = new \RuntimeException('synthetic-N6-write-failure');
            [$client,$buffer,$storage,$logs] = $this->setupClient($server, function ($message, int $session) use ($server, $kind, $cause) {
                if ($message->getType()->value !== 'HPUB') {
                    return;
                }
                $server->awaitCommandNameCount($session, 'HPUB', 1);
                if ($session === 0) {
                    if ($kind === 'write') {
                        throw $cause;
                    }$server->closeSession(0);
                } else {
                    $server->replyToLatestRequest($session, '{"stream":"S","seq":2}');
                }
            });
            try {
                try {
                    $this->publisher($client)->publish('definition', 'synthetic-N6-payload', $this->options(), 5000);
                    self::fail('Confirmation lost');
                } catch (ScheduledPublishException $error) {
                    self::assertTrue($error->isOutcomeUnknown());
                    if ($kind === 'write') {
                        $root = $error;
                        while ($root->getPrevious() !== null) {
                                $root = $root->getPrevious();
                        }self::assertSame($cause, $root);
                    }
                }
                $session = 1;
                $this->wait(function () use ($server, &$session): bool {
                    for ($i = 1; $i < 5; $i++) {
                        if (in_array('PING', $server->commandNames($i), true)) {
                            $session = $i;
                            return true;
                        }
                    }return false;
                });
                $this->ready($client, $server, $session);
                $this->wait(fn()=>$buffer->getPendingMessages() === 0);
                self::assertCount(1, $this->frames($server, 0));
                for ($i = 1; $i <= $session; $i++) {
                    self::assertCount(0, $this->frames($server, $i));
                }self::assertFalse($buffer->hasFailedFrame());
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame([], $storage->all());
                $ack = $this->publisher($client)->publish('definition.new', 'new', $this->options());
                self::assertSame(2, $ack->getSeq());
                $this->barrier($client, $server, $session);
                self::assertCount(1, $this->frames($server, $session));
                $this->trace('loss-' . $kind, ['connection0' => $this->frames($server, 0),'new_connection' => $this->frames($server, $session),'new_connection_index' => $session,'old_attempts' => 1,'old_after_reconnect' => 0,'new_success' => true]);
            } finally {
                $client->disconnect();
                $server->stop();
                $logs->assertSafe(['synthetic-N6-payload','synthetic-N6-write-failure']);
            }
        });
    }
    public function testExpiredQueuedDefinitionNeverReachesSocket(): void
    {
        $this->setTimeout(10);$this->runAsyncTest(function (): void {
            $server                          = new FakeNatsProtocolServer();
            [$client,$buffer,$storage,$logs] = $this->setupClient($server, static function () {
            });
            try {
                $buffer->pause();
                delay(.001);
                $pending = async(fn()=>$this->publisher($client)->publish('definition', 'synthetic-N6-payload', $this->options(), 100));
                $this->wait(fn()=>$buffer->getPendingMessages() >= 2);
                usleep(150000);
                $buffer->resume();
                try {
                    $pending->await();
                    self::fail('Queued expired definition');
                } catch (ScheduledPublishException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertSame(408, $error->getApiCode());
                }
                $this->barrier($client, $server, 0);
                self::assertSame([], $this->frames($server, 0));
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame([], $storage->all());
                self::assertFalse($buffer->hasFailedFrame());
                $this->trace('queued-expired', ['frames' => [],'pending_bytes' => 0,'unknown' => false,'next_request_success' => true]);
            } finally {
                $buffer->resume();
                $client->disconnect();
                $server->stop();
                $logs->assertSafe(['synthetic-N6-payload']);
            }
        });
    }
    private function trace(string $name, array $data): void
    {
        if ($dir = getenv('NATS_SCHEDULE_TRACE_DIR')) {
            file_put_contents($dir . '/' . $name . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
