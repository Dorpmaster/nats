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
        private string|null $deliverSubject = null,
        private bool|null $flowControl = null,
        private int|null $idleHeartbeatMs = null,
        private int|null $replicas = null,
    ) {
        if ($deliverSubject !== null) {
            new StreamSourceConsumer($durableName, $deliverSubject);
        }
        if ($replicas !== null && ($replicas < 1 || $replicas > 5)) {
            throw new \InvalidArgumentException('Invalid consumer replicas');
        }
        if ($idleHeartbeatMs !== null && ($idleHeartbeatMs < 100 || $idleHeartbeatMs > intdiv(PHP_INT_MAX, 1_000_000))) {
            throw new \InvalidArgumentException('Invalid idleHeartbeatMs');
        }
        if ($ackPolicy === 'flow_control' && ($deliverSubject === null || $flowControl === false || ($idleHeartbeatMs !== null && $idleHeartbeatMs !== 1000) || ($ackWaitMs !== null && $ackWaitMs !== 0) || ($maxAckPending !== null && $maxAckPending <= 0))) {
            throw new \InvalidArgumentException('Invalid flow_control consumer: requires push, flow control, 1000ms heartbeat, positive maxAckPending and no ackWait');
        }
    }
    public function getAckPolicy(): string
    {
        return $this->ackPolicy;
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

        if ($this->deliverSubject !== null) {
            $payload['deliver_subject'] = $this->deliverSubject;
        }
        if ($this->flowControl !== null || $this->ackPolicy === 'flow_control') {
            $payload['flow_control'] = $this->flowControl ?? true;
        }
        if ($this->idleHeartbeatMs !== null || $this->ackPolicy === 'flow_control') {
            $payload['idle_heartbeat'] = ($this->idleHeartbeatMs ?? 1000) * 1_000_000;
        }
        if ($this->replicas !== null) {
            $payload['num_replicas'] = $this->replicas;
        }
        if ($this->ackPolicy === 'flow_control' && $this->maxAckPending === null) {
            $payload['max_ack_pending'] = 1000;
        }
        return $payload;
    }
}
