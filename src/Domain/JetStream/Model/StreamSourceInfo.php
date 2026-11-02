<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

/** Observed replication state, distinct from the requested source in config. */
final readonly class StreamSourceInfo
{
    public string|null $name;
    public string|null $lag;
    public string|null $activeNanoseconds;
    public string|null $sequence;
    public StreamSourceError|null $error;
    public function __construct(public JsonObject $raw)
    {
        $fields     = new MetadataFields($raw, 'source');
        $this->name = $fields->text('name');
        $this->lag  = $fields->decimal('lag');
        // NATS uses -1 when the link has not received a message yet.
        $this->activeNanoseconds = $fields->signedDecimal('active');
        $this->sequence          = $fields->decimal('seq');
        $this->error             = ($error = $fields->object('error')) === null ? null : new StreamSourceError($error);
    }
}
