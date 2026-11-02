<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

/** Immutable typed view; raw retains unknown fields and original JSON tokens. */
final readonly class StreamStateInfo
{
    public string|null $messages;
    public string|null $bytes;
    public string|null $firstSequence;
    public string|null $lastSequence;
    public NanoTimestamp|null $firstTime;
    public NanoTimestamp|null $lastTime;

    public function __construct(public JsonObject $raw)
    {
        $fields              = new MetadataFields($raw, 'StreamStateInfo');
        $this->messages      = $fields->decimal('messages');
        $this->bytes         = $fields->decimal('bytes');
        $this->firstSequence = $fields->decimal('first_seq');
        $this->lastSequence  = $fields->decimal('last_seq');
        $this->firstTime     = $fields->timestamp('first_ts');
        $this->lastTime      = $fields->timestamp('last_ts');
    }
}
