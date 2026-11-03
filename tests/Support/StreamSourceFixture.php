<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\{StreamConfig,ConsumerConfig,StreamSource,StreamSourceConsumer,StreamConfigPatch,StreamInfo,JsonObject};
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;

final class StreamSourceFixture
{
    public JetStreamResetFixture $writer;
    public JetStreamResetFixture $reader;
    public RecordingStreamSourceTransport $transport;
    public JetStreamAdmin $admin;
    public JetStreamPublisher $publisher;
    public string $origin;
    public string $target;
    public string $subject;
    public string $deliverSubject;
    public function __construct(public string $retention = 'limits', public int $replicas = 1)
    {
        $this->writer         = new JetStreamResetFixture();
        $this->reader         = new JetStreamResetFixture();
        $this->transport      = new RecordingStreamSourceTransport(new JetStreamControlPlaneTransport($this->writer->client));
        $this->admin          = new JetStreamAdmin($this->transport);
        $this->publisher      = new JetStreamPublisher($this->writer->client);
        $suffix               = strtoupper(bin2hex(random_bytes(5)));
        $this->origin         = 'N7_O_' . $suffix;
        $this->target         = 'N7_T_' . $suffix;
        $this->subject        = 'n7.' . $suffix . '.input';
        $this->deliverSubject = 'n7.' . $suffix . '.delivery';
        try {
            $this->admin->createStream(new StreamConfig($this->origin, [$this->subject], 'file', $replicas, retention:$retention));
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }
    public function ownedConsumer(): void
    {
        $this->admin->createOrUpdateConsumer($this->origin, new ConsumerConfig('OWNED', ackPolicy:getenv('NATS_EXPECTED_VERSION') === '2.12.6' ? 'explicit' : 'flow_control', deliverSubject:$this->deliverSubject, replicas:$this->replicas));
    }
    public function create(string $mode, bool $owned): StreamInfo
    {
        $source = new StreamSource($this->origin, $owned ? new StreamSourceConsumer('OWNED', $this->deliverSubject) : null);
        return $this->admin->createStream(new StreamConfig($this->target, [], 'file', $this->replicas, mirror:$mode === 'mirror' ? $source : null, sources:$mode === 'source' ? [$source] : null));
    }
    public function remove(string $mode): StreamInfo
    {
        return $this->admin->updateStream($this->target, $mode === 'mirror' ? new StreamConfigPatch(mirror:false) : new StreamConfigPatch(sources:[]));
    }
    public function rows(): array
    {
        $info = $this->reader->admin->getStreamInfo($this->target);
        $rows = [];
        for ($seq = 1; $seq <= (int)$info->getState()->lastSequence; $seq++) {
            try {
                $msg = $this->reader->transport->requestJson('STREAM.MSG.GET.' . $this->target, new JsonObject('{"seq":' . $seq . '}'))->getObject('message');
            } catch (JetStreamApiException $error) {
                if ($error->getApiCode() === 404) {
                    continue;
                }
                throw $error;
            }
            $rows[] = ['sequence' => $msg->getRawValue('seq'),'subject' => $msg->get('subject'),'payload_hex' => bin2hex(base64_decode($msg->get('data') ?? '', true)),'headers_base64' => $msg->get('hdrs'),'time' => $msg->get('time')];
        }
        return $rows;
    }
    public function waitRows(int $count): array
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $rows = $this->rows();
            if (count($rows) >= $count) {
                return $rows;
            }
            \Amp\delay(.03);
        } while (hrtime(true) < $deadline);
        throw new \RuntimeException('Source target delivery timed out');
    }
    public function controls(int $from = 0): array
    {
        return array_values(array_filter(array_slice($this->transport->records, $from), static fn(array $record): bool=>preg_match('/\.(?:STREAM|CONSUMER)\.(?:CREATE|UPDATE|DELETE)\./', $record['subject']) === 1));
    }
    public function trace(string $name, array $observations): void
    {
        if ($path = getenv('NATS_SOURCES_TRACE_DIR')) {
            file_put_contents($path . '/' . $name . '.json', json_encode(['requests' => $this->transport->records,'origin' => $this->origin,'target' => $this->target,'observations' => $observations], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }
    public function close(): void
    {
        try {
            foreach ([$this->target ?? null,$this->origin ?? null] as $stream) {
                if ($stream !== null) {
                    try {
                        $this->admin->deleteStream($stream);
                    } catch (JetStreamApiException $error) {
                        if ($error->getApiCode() !== 404) {
                            throw $error;
                        }
                    }
                }
            }
        } finally {
            $this->reader->close();
            $this->writer->close();
        }
    }
}
