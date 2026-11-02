<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\NullCancellation;
use Amp\Socket\DnsSocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;

final class JetStreamPlacementFixture
{
    public Client $client;
    public RecordingPlacementTransport $transport;
    public JetStreamAdmin $admin;
    public RecordingLogger $logs;
    public string $stream;
    public string $subject;
    private bool $created = false;

    /** @param \Closure(\Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface,int):void|null $onSend */
    public function __construct(string $account = 'A', string $prefix = '$JS.API', \Closure|null $onSend = null)
    {
        $this->logs    = new RecordingLogger();
        $storage       = new SubscriptionStorage();
        $cluster       = getenv('NATS_RESET_CLUSTER') === '1';
        $configuration = $cluster ? new ClientConfiguration(reconnectEnabled: true, maxReconnectAttempts: 30, reconnectBackoffInitialMs: 20, reconnectBackoffMaxMs: 100, reconnectJitterFraction: 0.0, servers: NatsJetStreamClusterHarness::getSeedServers()) : new ClientConfiguration();
        $connection    = new Connection(new DnsSocketConnector(), new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port()), $this->logs);
        if ($onSend !== null) {
            $connection = new ControlledWriteConnection($connection, after: $onSend);
        }
        $this->client    = new Client(
            configuration: $configuration,
            cancellation: new NullCancellation(),
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, user: strtolower($account), pass: 'synthetic-n8-' . strtolower($account), headers: true, protocol: 1), $storage, $this->logs),
            storage: $storage,
            logger: $this->logs,
        );
        $this->transport = new RecordingPlacementTransport(new JetStreamControlPlaneTransport($this->client, logger: $this->logs, apiPrefix: $prefix));
        $this->admin     = new JetStreamAdmin($this->transport, $this->logs);
        $suffix          = bin2hex(random_bytes(5));
        $this->stream    = 'N8_PLACEMENT_' . strtoupper($suffix);
        $this->subject   = 'n8.placement.' . $suffix;
        $this->client->connect();
    }

    /** @param array<string, mixed> $configuration */
    public function seed(int $messages = 6, array $configuration = [], int $replicas = 1): void
    {
        $this->admin->createStream(new StreamConfig($this->stream, [$this->subject . '.*'], 'file', $replicas));
        $this->created = true;
        $this->createConsumer('C', $configuration + ($replicas > 1 ? ['num_replicas' => $replicas] : []));
        for ($index = 1; $index <= $messages; $index++) {
            $subject = $this->subject . ($index % 2 === 1 ? '.a' : '.b');
            $this->client->request(new PubMessage($subject, self::payload($index)), 3);
        }
    }

    /** @param array<string, mixed> $configuration */
    public function createConsumer(string $name, array $configuration = []): void
    {
        $this->transport->requestJson('CONSUMER.CREATE.' . $this->stream . '.' . $name, JsonObject::fromArray([
            'stream_name' => $this->stream,
            'config' => $configuration + ['durable_name' => $name, 'ack_policy' => 'explicit', 'ack_wait' => 30_000_000_000],
        ]));
    }

    public function pull(string $consumer = 'C'): JetStreamPullConsumer
    {
        return new JetStreamPullConsumer($this->transport, $this->stream, $consumer);
    }

    public static function payload(int $index): string
    {
        return 'synthetic-n8-message-' . $index . "\r\n\0";
    }

    public function close(): void
    {
        try {
            if ($this->created && $this->client->getState() !== \Dorpmaster\Nats\Domain\Client\ClientState::CLOSED) {
                $this->admin->deleteStream($this->stream);
            }
        } finally {
            $this->client->disconnect();
        }
    }
}
