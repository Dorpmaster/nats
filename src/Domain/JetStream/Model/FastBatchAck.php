<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;

final readonly class FastBatchAck
{
    private string $count;
    private string $seq;
    public function __construct(private string $stream, private string $batchId, string $count, string $seq, private JsonObject $response)
    {
        $this->count = Decimal::parse($count, Decimal::UINT64_MAX);
        $this->seq   = Decimal::parse($seq, Decimal::UINT64_MAX);
        if ($stream === '' || $batchId === '') {
            throw new \InvalidArgumentException('Invalid fast batch final acknowledgement');
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
    public function getCount(): string
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
