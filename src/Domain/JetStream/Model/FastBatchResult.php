<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Throwable;

final readonly class FastBatchResult
{
    /** @param list<array{from:string,to:string}> $unknownRanges */
    public function __construct(private FastBatchProgress $progress, private bool $complete, private bool $outcomeUnknown, private array $unknownRanges, private FastBatchAck|null $ack, private string|null $problemSequence, private Throwable|null $cause, private bool $cancelled = false)
    {
    }
    public function getProgress(): FastBatchProgress
    {
        return $this->progress;
    }
    public function getBatchId(): string
    {
        return $this->progress->getBatchId();
    }
    public function getLastSentSequence(): string
    {
        return $this->progress->getLastSentSequence();
    }
    public function getLastAcknowledgedSequence(): string
    {
        return $this->progress->getLastAcknowledgedSequence();
    }
    public function isComplete(): bool
    {
        return $this->complete;
    }
    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
    /** @return list<array{from:string,to:string}> */
    public function getUnknownRanges(): array
    {
        return $this->unknownRanges;
    }
    public function getFinalAck(): FastBatchAck|null
    {
        return $this->ack;
    }
    public function getProblemSequence(): string|null
    {
        return $this->problemSequence;
    }
    public function getCause(): Throwable|null
    {
        return $this->cause;
    }
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
