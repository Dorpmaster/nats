<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

/** Immutable typed view; raw retains unknown fields and original JSON tokens. */
final readonly class SequenceInfo
{
    public string|null $consumerSequence;
    public string|null $streamSequence;
    public NanoTimestamp|null $lastActive;

    public function __construct(public JsonObject $raw)
    {
        $fields                 = new MetadataFields($raw, 'SequenceInfo');
        $this->consumerSequence = $fields->decimal('consumer_seq');
        $this->streamSequence   = $fields->decimal('stream_seq');
        $this->lastActive       = $fields->timestamp('last_active');
    }
}
