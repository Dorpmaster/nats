<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;

final class FastBatchFixture
{
    public Client $writer;
    public Client $reader;
    public JetStreamAdmin $admin;
    public JetStreamAdmin $readerAdmin;
    public JetStreamControlPlaneTransport $readerTransport;
    public FastBatchProbeClient $probe;
    public string $stream;
    public array $subjects;
    private bool $created = false;

    public function __construct(bool $enabled = true, int $replicas = 1)
    {
        $this->writer          = self::client();
        $this->reader          = self::client();
        $this->admin           = new JetStreamAdmin(new JetStreamControlPlaneTransport($this->writer));
        $this->readerTransport = new JetStreamControlPlaneTransport($this->reader);
        $this->readerAdmin     = new JetStreamAdmin($this->readerTransport);
        $this->stream          = 'N5_' . strtoupper(bin2hex(random_bytes(5)));
        $prefix                = 'n5.' . bin2hex(random_bytes(5));
        $this->subjects        = [$prefix . '.a', $prefix . '.b'];
        try {
            $version = getenv('NATS_EXPECTED_VERSION') ?: '2.15.2';
            $old     = version_compare($version, '2.14.0', '<');
            $this->admin->createStream(new StreamConfig($this->stream, $this->subjects, 'file', $replicas, allowAtomicPublish: version_compare($version, '2.12.0', '<') ? null : false, allowBatchPublish: $old ? null : $enabled));
            $this->created = true;
            $this->readerAdmin->getStreamInfo($this->stream);
            $this->probe = new FastBatchProbeClient($this->writer);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }
    private static function client(): Client
    {
        $storage       = new SubscriptionStorage();
        $cluster       = getenv('NATS_RESET_CLUSTER') === '1';
        $configuration = $cluster ? new ClientConfiguration(reconnectEnabled: true, maxReconnectAttempts: 30, reconnectBackoffInitialMs: 20, reconnectBackoffMaxMs: 100, reconnectJitterFraction: 0.0, servers: NatsJetStreamClusterHarness::getSeedServers()) : new ClientConfiguration();
        $client        = new Client($configuration, new \Amp\NullCancellation(), new Connection(new \Amp\Socket\DnsSocketConnector(), new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port())), new EventDispatcher(), new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, headers: true, protocol: 1), $storage), $storage);
        $client->connect();
        return $client;
    }
    public function read(int $limit = 100): array
    {
        $count = (int) $this->readerAdmin->getStreamInfo($this->stream)->getState()->messages;
        $rows  = [];
        if ($count === 0) {
            try {
                $this->readerTransport->requestJson('STREAM.MSG.GET.' . $this->stream, new JsonObject('{"seq":1}'));
                throw new \LogicException('Empty fast stream exposed data');
            } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $error) {
                if ($error->getApiCode() !== 404 || $error->getErrorCode() !== 10037) {
                    throw $error;
                }
            }
        }
        for ($seq = 1; $seq <= min($count, $limit); $seq++) {
            $raw    = $this->readerTransport->requestJson('STREAM.MSG.GET.' . $this->stream, new JsonObject('{"seq":' . $seq . '}'))->getObject('message');
            $rows[] = ['seq' => $raw->getRawValue('seq'), 'subject' => $raw->get('subject'), 'payload_hex' => bin2hex(base64_decode($raw->get('data') ?? '', true)), 'headers_base64' => $raw->get('hdrs')];
        }
        return $rows;
    }
    public function close(): void
    {
        try {
            if ($this->created) {
                $this->admin->deleteStream($this->stream);
            }
        } finally {
            $this->writer->disconnect();
            $this->reader->disconnect();
        }
    }
}
