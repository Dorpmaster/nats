<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

final readonly class ConsumerInfo
{
    private string|null $name;
    private string|null $streamName;
    private NanoTimestamp|null $created;
    private NanoTimestamp|null $timestamp;
    private string|null $pendingCount;
    private string|null $ackPendingCount;
    private string|null $redeliveredCount;
    private string|null $waitingCount;
    private SequenceInfo|null $delivered;
    private SequenceInfo|null $ackFloor;
    private ClusterInfo|null $cluster;
    private JsonObject|null $metadata;

    public function __construct(
        private string $durableName,
        private int|null $numPending = null,
        private int|null $numAckPending = null,
        private JsonObject|null $configuration = null,
        private JsonObject|null $response = null,
    ) {
        $fields                 = new MetadataFields($response ?? new JsonObject('{}'), 'consumer');
        $this->name             = $fields->text('name');
        $this->streamName       = $fields->text('stream_name');
        $this->created          = $fields->timestamp('created');
        $this->timestamp        = $fields->timestamp('ts');
        $this->pendingCount     = $fields->decimal('num_pending');
        $this->ackPendingCount  = $fields->decimal('num_ack_pending', Decimal::INT64_MAX);
        $this->redeliveredCount = $fields->decimal('num_redelivered', Decimal::INT64_MAX);
        $this->waitingCount     = $fields->decimal('num_waiting', Decimal::INT64_MAX);
        $this->delivered        = ($object = $fields->object('delivered')) === null ? null : new SequenceInfo($object);
        $this->ackFloor         = ($object = $fields->object('ack_floor')) === null ? null : new SequenceInfo($object);
        $this->cluster          = ($object = $fields->object('cluster')) === null ? null : new ClusterInfo($object);
        $this->metadata         = (new MetadataFields($configuration ?? new JsonObject('{}'), 'config'))->object('metadata');
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

    public function getName(): string|null
    {
        return $this->name;
    }

    public function getStreamName(): string|null
    {
        return $this->streamName;
    }

    public function getCreated(): NanoTimestamp|null
    {
        return $this->created;
    }

    public function getTimestamp(): NanoTimestamp|null
    {
        return $this->timestamp;
    }

    public function getPendingCount(): string|null
    {
        return $this->pendingCount;
    }

    public function getAckPendingCount(): string|null
    {
        return $this->ackPendingCount;
    }

    public function getRedeliveredCount(): string|null
    {
        return $this->redeliveredCount;
    }

    public function getWaitingCount(): string|null
    {
        return $this->waitingCount;
    }

    public function getDelivered(): SequenceInfo|null
    {
        return $this->delivered;
    }

    public function getAckFloor(): SequenceInfo|null
    {
        return $this->ackFloor;
    }

    public function getCluster(): ClusterInfo|null
    {
        return $this->cluster;
    }

    public function getMetadata(): JsonObject|null
    {
        return $this->metadata;
    }

    public function getConfiguration(): JsonObject|null
    {
        return $this->configuration;
    }

    public function getResponse(): JsonObject|null
    {
        return $this->response;
    }
}
