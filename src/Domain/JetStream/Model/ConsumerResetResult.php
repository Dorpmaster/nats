<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;

/** Confirmed server reset position, not necessarily the sequence of the first matching delivery. */
final readonly class ConsumerResetResult
{
    private string $resetSequence;

    public function __construct(string $resetSequence, private ConsumerInfo $consumerInfo)
    {
        $this->resetSequence = Decimal::parse($resetSequence, Decimal::UINT64_MAX, true);
    }

    public function getResetSequence(): string
    {
        return $this->resetSequence;
    }

    public function getConsumerInfo(): ConsumerInfo
    {
        return $this->consumerInfo;
    }
}
