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

final class ScheduleFixture
{
    public Client $writer;
    public Client $reader;
    public JetStreamAdmin $admin;
    public JetStreamAdmin $readerAdmin;
    public JetStreamControlPlaneTransport $readerTransport;
    public AtomicBatchProbeClient $probe;
    public string $definition;
    public string $target;
    public string $source;
    public string $neighbor;
    public string $stream;
    public array $subjects;
    private bool $created = false;

    public function __construct(bool $enabled = true, bool $ttl = true)
    {
        $this->writer          = self::client();
        $this->reader          = self::client();
        $this->admin           = new JetStreamAdmin(new JetStreamControlPlaneTransport($this->writer));
        $this->readerTransport = new JetStreamControlPlaneTransport($this->reader);
        $this->readerAdmin     = new JetStreamAdmin($this->readerTransport);
        $this->stream          = 'N6_' . strtoupper(bin2hex(random_bytes(5)));
        $prefix                = 'n6.' . bin2hex(random_bytes(5));
        $this->definition      = $prefix . '.schedule';
        $this->target          = $prefix . '.target';
        $this->source          = $prefix . '.source';
        $this->neighbor        = $prefix . '.neighbor';
        $this->subjects        = [$prefix . '.>'];
        try {
            $this->admin->createStream(new StreamConfig($this->stream, $this->subjects, 'file', 1, allowMessageSchedules: $enabled, allowMessageTtl: $ttl));
            $this->created = true;
            $this->readerAdmin->getStreamInfo($this->stream);
            $this->probe = new AtomicBatchProbeClient($this->writer);
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
    /** @return list<array{seq:string,subject:string,payload_hex:string,headers:string,time:string}> */
    public function rows(string|null $subject = null): array
    {
        $info = $this->readerAdmin->getStreamInfo($this->stream);
        $last = (int) $info->getState()->lastSequence;
        $rows = [];
        for ($sequence = 1; $sequence <= $last; $sequence++) {
            try {
                $message = $this->readerTransport->requestJson('STREAM.MSG.GET.' . $this->stream, new JsonObject('{"seq":' . $sequence . '}'))->getObject('message');
            } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $error) {
                if ($error->getApiCode() === 404) {
                    continue;
                }
                throw $error;
            }
            if ($subject !== null && $message->get('subject') !== $subject) {
                continue;
            }
            $rows[] = ['seq' => $message->getRawValue('seq'),'subject' => $message->get('subject'),'payload_hex' => bin2hex(base64_decode($message->get('data') ?? '', true)), 'headers' => base64_decode($message->get('hdrs') ?? '', true), 'time' => $message->get('time')];
        }
        return $rows;
    }
    public function stop(): \Dorpmaster\Nats\Domain\JetStream\Model\JsonObject
    {
        return $this->readerTransport->requestJson('STREAM.PURGE.' . $this->stream, JsonObject::fromArray(['filter' => $this->definition]));
    }
    public function waitRows(string $subject, int $count, float $seconds = 5): array
    {
        $deadline = hrtime(true) + $seconds * 1000000000;
        do {
            $rows = $this->rows($subject);
            if (count($rows) >= $count) {
                return $rows;
            }
            \Amp\delay(.02);
        } while (hrtime(true) < $deadline);
        throw new \RuntimeException('Scheduled target delivery timed out');
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
