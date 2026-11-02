<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Throwable;

final class AtomicBatchException extends JetStreamApiException
{
    public function __construct(private readonly string $batchId, private readonly bool $outcomeUnknown, Throwable $previous)
    {
        $cause   = $previous instanceof ConnectionBoundRequestException ? $previous->getPrevious() : $previous;
        $timeout = $cause instanceof CancelledException && $cause->getPrevious() instanceof \Amp\TimeoutException;
        parent::__construct(
            $previous instanceof JetStreamApiException ? $previous->getApiCode() : ($timeout ? 408 : 0),
            $previous instanceof JetStreamApiException ? $previous->getDescription() : ($outcomeUnknown ? 'Commit confirmation was not received' : 'Atomic batch stopped before a confirmed commit'),
            $previous,
            $previous instanceof JetStreamApiException ? $previous->getErrorCode() : null,
        );
        $this->message = 'Atomic batch ' . $batchId . ($outcomeUnknown ? ' (commit outcome is unknown)' : '') . ': ' . $this->getDescription();
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }
    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
}
