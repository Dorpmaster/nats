<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

use Throwable;

/** Failure is never a completed archive; confirmed bytes exclude a failing partial IO call. */
final class JetStreamArchiveException extends JetStreamApiException
{
    public function __construct(private readonly string $operation, private readonly string $stream, private readonly int $transferredBytes, private readonly bool $outcomeUnknown, private readonly bool $partial, Throwable $cause)
    {
        parent::__construct($cause instanceof JetStreamApiException ? $cause->getApiCode() : 0, $cause instanceof JetStreamApiException ? $cause->getDescription() : 'JetStream ' . $operation . ' failed', $cause, $cause instanceof JetStreamApiException ? $cause->getErrorCode() : null);
    }
    public function getOperation(): string
    {
        return $this->operation;
    }
    public function getStream(): string
    {
        return $this->stream;
    }
    public function getTransferredBytes(): int
    {
        return $this->transferredBytes;
    }
    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
    public function mayHavePartialOutput(): bool
    {
        return $this->partial;
    }
}
