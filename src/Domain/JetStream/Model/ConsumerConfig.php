<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

final readonly class ConsumerConfig
{
    public function __construct(
        private string $durableName,
        private string $ackPolicy = 'explicit',
        private int|null $ackWaitMs = null,
        private int|null $maxAckPending = null,
        private string|null $filterSubject = null,
    ) {
    }

    public function getDurableName(): string
    {
        return $this->durableName;
    }

    public function toRequestPayload(): array
    {
        $payload = [
            'durable_name' => $this->durableName,
            'ack_policy' => $this->ackPolicy,
        ];

        if ($this->ackWaitMs !== null) {
            $payload['ack_wait'] = $this->ackWaitMs * 1_000_000;
        }

        if ($this->maxAckPending !== null) {
            $payload['max_ack_pending'] = $this->maxAckPending;
        }

        if ($this->filterSubject !== null) {
            $payload['filter_subject'] = $this->filterSubject;
        }

        return $payload;
    }
}
