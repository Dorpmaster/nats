<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Client\WriteBufferService;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;

use function Amp\async;
use function Amp\delay;

/** Full Client → queue → socket harness; captures actual complete frames on the peer. */
final class FastBatchTcpHarness
{
    public FakeNatsProtocolServer $server;
    public Client $client;
    public WriteBufferService $buffer;
    public SubscriptionStorage $storage;
    public CapturedProtocolLogs $logs;
    public \Closure|null $afterFrame = null;

    public function __construct()
    {
        $this->server  = new FakeNatsProtocolServer();
        $this->logs    = new CapturedProtocolLogs();
        $this->storage = new SubscriptionStorage();
        $this->buffer  = new WriteBufferService(100, 1000000);
        $connection    = new ControlledWriteConnection(new Connection(new \Amp\Socket\DnsSocketConnector(), new ConnectionConfiguration($this->server->host(), $this->server->port())), after: function ($message, int $session): void {
            $command = explode("\r\n", (string) $message, 2)[0];
            $parts   = explode(' ', $command);
            if (str_starts_with($command, 'PUB $JS.API.') || str_starts_with($command, 'PUB $SYS.REQ.')) {
                $this->wait(fn (): bool => count(array_filter($this->server->publications($session), static fn ($f): bool => $f['command'] === $command)) === 1);
                $payload = AtomicBatchTopologyResponses::payload($parts[1], 'fake') ?? (str_contains($parts[1], '.INFO.')
                    ? '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_atomic":false,"allow_batched":true,"metadata":{"_nats.level":"5"}}}'
                    : '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":1,"streams":["S"]}');
                $this->server->replyToLatestRequest($session, $payload);
            } elseif ($parts[0] === 'HPUB') {
                $this->wait(fn (): bool => count(array_filter($this->server->publications($session), static fn ($f): bool => $f['command'] === $command)) === 1);
                $frames = array_values(array_filter($this->frames(), static fn ($f): bool => $f['command'] === $command && $f['session'] === $session));
                if ($this->afterFrame !== null) {
                    ($this->afterFrame)($frames[0]);
                } else {
                    $this->ack($frames[0]);
                }
            }
        });
        $this->client  = new Client(new ClientConfiguration(reconnectEnabled: true, maxReconnectAttempts: 5, reconnectBackoffInitialMs: 1, reconnectBackoffMaxMs: 1, reconnectJitterFraction: 0.0, bufferWhileReconnecting: true), new \Amp\NullCancellation(), $connection, new EventDispatcher(), new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, headers: true, protocol: 1), $this->storage, $this->logs), $this->storage, $this->logs, writeBuffer: $this->buffer);
        $this->client->connect();
        $this->server->awaitCommandNameCount(0, 'PING', 1);
        $this->server->releasePong(0);
        $this->barrier(0);
        $this->server->sendInfo(0, ['api_lvl' => 5, 'version' => '2.15.2', 'jetstream' => true]);
        $this->wait(fn (): bool => $this->client->getServerInformation()->apiLevel === 5);
    }
    public function frames(): array
    {
        $frames = [];
        for ($session = 0; $session < 5; $session++) {
            foreach ($this->server->publications($session) as $record) {
                if (!str_starts_with($record['command'], 'HPUB ')) {
                    continue;
                }
                $tokens   = explode(' ', $record['command']);
                $reply    = $tokens[2];
                $p        = explode('.', $reply);
                $frames[] = $record + ['session' => $session, 'reply' => $reply, 'sequence' => (int) $p[count($p) - 3], 'operation' => (int) $p[count($p) - 2], 'batch' => $p[count($p) - 6]];
            }
        }
        return $frames;
    }
    public function reply(array $frame, string $payload): void
    {
        foreach ($this->server->rawCommands($frame['session']) as $command) {
            $p = explode(' ', $command);
            if ($p[0] === 'SUB' && str_ends_with($p[1], '.>') && str_starts_with($frame['reply'], substr($p[1], 0, -1))) {
                $this->server->sendMessage($frame['session'], $frame['reply'], $p[2], $payload);
                return;
            }
        }
        throw new \LogicException('No control inbox subscription was received before data');
    }
    public function ack(array $frame): void
    {
        if ($frame['operation'] === 0) {
            $this->reply($frame, '{"type":"ack","seq":0,"msgs":1}');
        }
        if ($frame['operation'] === 2 || $frame['operation'] === 3) {
            $count = $frame['operation'] === 3 ? $frame['sequence'] - 1 : $frame['sequence'];
            $this->reply($frame, '{"stream":"S","batch":"' . $frame['batch'] . '","count":' . $count . ',"seq":' . (1000 + $count) . '}');
        } else {
            $this->reply($frame, '{"type":"ack","seq":' . $frame['sequence'] . ',"msgs":1}');
        }
    }
    public function barrier(int $session): void
    {
        $before = count(array_filter($this->server->commandNames($session), static fn ($name): bool => $name === 'PUB'));
        $future = async(fn () => $this->client->request(new PubMessage('probe.next', 'control'), 1));
        $this->server->awaitCommandNameCount($session, 'PUB', $before + 1);
        $this->server->replyToLatestRequest($session, 'ok');
        if ($future->await()->getPayload() !== 'ok') {
            throw new \LogicException('Control exchange failed');
        }
        delay(.001);
    }
    public function reconnect(): int
    {
        $session = 1;
        $this->wait(function () use (&$session): bool {
            for ($i = 1; $i < 5; $i++) {
                if (in_array('PING', $this->server->commandNames($i), true)) {
                    $session = $i;
                    return true;
                }
            }
            return false;
        });
        $this->server->sendInfo($session, ['api_lvl' => 5, 'version' => '2.15.2', 'jetstream' => true]);
        $this->server->releasePong($session);
        $this->barrier($session);
        return $session;
    }
    public function wait(\Closure $predicate): void
    {
        $deadline = hrtime(true) + 3_000_000_000;
        while (!$predicate()) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException('TCP barrier timed out');
            } delay(.001);
        }
    }
    public function close(): void
    {
        $this->client->disconnect();
        $this->server->stop();
        $this->logs->assertSafe(['synthetic-N5-write-failure', 'synthetic-N5-payload']);
    }
}
