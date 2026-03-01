<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

final readonly class PullConsumeOptions
{
    public function __construct(
        public int $batch,
        public int $expiresMs,
        public bool $noWait = false,
        public int|null $maxBytes = null,
        public int|null $idleHeartbeatMs = null,
        public int $maxInFlightMessages = 1_000,
        public int $maxInFlightBytes = 5_000_000,
        public SlowConsumerPolicy $policy = SlowConsumerPolicy::ERROR,
    ) {
        if ($this->batch <= 0) {
            throw new \InvalidArgumentException('batch must be greater than zero');
        }

        if ($this->expiresMs <= 0) {
            throw new \InvalidArgumentException('expiresMs must be greater than zero');
        }

        if ($this->maxInFlightMessages <= 0) {
            throw new \InvalidArgumentException('maxInFlightMessages must be greater than zero');
        }

        if ($this->maxInFlightBytes <= 0) {
            throw new \InvalidArgumentException('maxInFlightBytes must be greater than zero');
        }
    }
}
