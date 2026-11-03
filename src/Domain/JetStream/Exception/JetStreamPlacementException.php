<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

use Throwable;

final class JetStreamPlacementException extends JetStreamApiException
{
    /** @param array<string, string> $targets */
    public function __construct(private readonly string $operation, private readonly array $targets, private readonly bool $outcomeUnknown, Throwable $previous)
    {
        parent::__construct($previous instanceof JetStreamApiException ? $previous->getApiCode() : 0, $previous instanceof JetStreamApiException ? $previous->getDescription() : 'JetStream placement operation failed', $previous, $previous instanceof JetStreamApiException ? $previous->getErrorCode() : null);
    }
    public function getOperation(): string
    {
        return $this->operation;
    }
    /** @return array<string, string> */
    public function getTargets(): array
    {
        return $this->targets;
    }
    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
}
