<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

final readonly class FastBatchProgress
{
    public function __construct(private string $stream, private string $batchId, private string $lastSentSequence, private string $lastAcknowledgedSequence, private int $window, private int $pendingMessages, private int $pendingBytes)
    {
    }
    public function getStream(): string
    {
        return $this->stream;
    }
    public function getBatchId(): string
    {
        return $this->batchId;
    }
    /** Highest user frame whose socket write began; not a persistence guarantee. */
    public function getLastSentSequence(): string
    {
        return $this->lastSentSequence;
    }
    public function getLastAcknowledgedSequence(): string
    {
        return $this->lastAcknowledgedSequence;
    }
    public function getWindow(): int
    {
        return $this->window;
    }
    public function getPendingMessages(): int
    {
        return $this->pendingMessages;
    }
    public function getPendingBytes(): int
    {
        return $this->pendingBytes;
    }
}
