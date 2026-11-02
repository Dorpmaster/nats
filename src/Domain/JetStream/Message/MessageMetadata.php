<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

use Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp;
use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;

/** Immutable ACK metadata. Counters and sequences are canonical decimal strings. */
final readonly class MessageMetadata
{
    public string $streamSequence;
    public string $consumerSequence;
    public string $deliveryCount;
    public string $pending;

    public function __construct(
        public string $stream,
        public string $consumer,
        string $streamSequence,
        string $consumerSequence,
        string $deliveryCount,
        string $pending,
        public NanoTimestamp $timestamp,
        public string|null $domain = null,
        public string|null $accountHash = null,
    ) {
        $this->streamSequence   = Decimal::parse($streamSequence, Decimal::UINT64_MAX, true);
        $this->consumerSequence = Decimal::parse($consumerSequence, Decimal::UINT64_MAX, true);
        $this->deliveryCount    = Decimal::parse($deliveryCount, Decimal::UINT64_MAX, true);
        $this->pending          = Decimal::parse($pending, Decimal::UINT64_MAX);
    }
}
