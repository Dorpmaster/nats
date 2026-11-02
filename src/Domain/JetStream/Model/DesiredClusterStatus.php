<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

/** Immutable typed view; raw retains unknown fields and original JSON tokens. */
final readonly class DesiredClusterStatus
{
    public string|null $type;
    public string|null $description;
    public string|null $error;

    public function __construct(public JsonObject $raw)
    {
        $fields            = new MetadataFields($raw, 'DesiredClusterStatus');
        $this->type        = $fields->text('type');
        $this->description = $fields->text('description');
        $this->error       = $fields->text('err');
    }
}
