<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;

final class AtomicBatchFixture
{
    public JetStreamResetFixture $writer;
    public JetStreamResetFixture $reader;
    public AtomicBatchProbeClient $probe;
    public JetStreamPublisher $publisher;
    public string $stream;
    public string $subject;
    public array $emptyReads = [];
    private bool $created    = false;

    public function __construct(bool $allowAtomic = true, int $replicas = 1)
    {
        $this->writer  = new JetStreamResetFixture();
        $this->reader  = new JetStreamResetFixture();
        $this->stream  = 'N4_ATOMIC_' . strtoupper(bin2hex(random_bytes(5)));
        $this->subject = 'n4.atomic.' . bin2hex(random_bytes(5));
        try {
            $this->writer->admin->createStream(new StreamConfig($this->stream, [$this->subject . '.*'], 'file', $replicas, allowAtomicPublish: $allowAtomic));
            $this->created = true;
            $this->reader->admin->getStreamInfo($this->stream);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
        $this->probe     = new AtomicBatchProbeClient($this->writer->client);
        $this->publisher = new JetStreamPublisher($this->probe, $this->writer->logs);
    }

    /** Independent connection and API reads, never publisher-local state. */
    public function read(): array
    {
        $info  = $this->reader->admin->getStreamInfo($this->stream);
        $count = (int) $info->getState()->messages;
        if ($count === 0) {
            try {
                $this->reader->transport->requestJson('STREAM.MSG.GET.' . $this->stream, new JsonObject('{"seq":1}'));
                throw new \LogicException('An empty stream exposed a readable first message');
            } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $error) {
                if ($error->getApiCode() !== 404 || $error->getErrorCode() !== 10037) {
                    throw $error;
                }
                $this->emptyReads[] = ['sequence' => '1', 'code' => $error->getApiCode(), 'err_code' => $error->getErrorCode(), 'description' => $error->getDescription()];
                return [];
            }
        }
        $messages = [];
        for ($seq = 1; $seq <= $count; $seq++) {
            $raw        = $this->reader->transport->requestJson('STREAM.MSG.GET.' . $this->stream, new JsonObject('{"seq":' . $seq . '}'));
            $message    = $raw->getObject('message');
            $messages[] = ['seq' => $message->getRawValue('seq'), 'subject' => $message->get('subject'), 'payload_hex' => bin2hex(base64_decode($message->get('data') ?? '', true)), 'headers_base64' => $message->get('hdrs')];
        }
        return $messages;
    }

    public function close(): void
    {
        try {
            if ($this->created) {
                $this->writer->admin->deleteStream($this->stream);
            }
        } finally {
            $this->reader->close();
            $this->writer->close();
        }
    }
}
