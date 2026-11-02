<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;

final readonly class AtomicBatchAck
{
    private string $seq;

    public function __construct(
        private string $stream,
        private string $batchId,
        private int $count,
        string $seq,
        private JsonObject $response,
    ) {
        $this->seq = Decimal::parse($seq, Decimal::UINT64_MAX, true);
        if ($count <= 0 || $stream === '' || $batchId === '') {
            throw new \InvalidArgumentException('Invalid atomic batch acknowledgement');
        }
    }

    public function getStream(): string
    {
        return $this->stream;
    }
    public function getBatchId(): string
    {
        return $this->batchId;
    }
    public function getCount(): int
    {
        return $this->count;
    }
    public function getSeq(): string
    {
        return $this->seq;
    }
    public function getResponse(): JsonObject
    {
        return $this->response;
    }
}
