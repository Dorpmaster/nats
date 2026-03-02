<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

final readonly class ConsumerInfo
{
    public function __construct(
        private string $durableName,
        private int|null $numPending = null,
        private int|null $numAckPending = null,
    ) {
    }

    public function getDurableName(): string
    {
        return $this->durableName;
    }

    public function getNumPending(): int|null
    {
        return $this->numPending;
    }

    public function getNumAckPending(): int|null
    {
        return $this->numAckPending;
    }
}
