<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

final readonly class ConsumerInfo
{
    public function __construct(
        private string $durableName,
    ) {
    }

    public function getDurableName(): string
    {
        return $this->durableName;
    }
}
