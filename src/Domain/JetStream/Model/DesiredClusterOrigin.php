<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;

/** Immutable typed view; raw retains unknown fields and original JSON tokens. */
final readonly class DesiredClusterOrigin
{
    public string|null $replicas;
    public JsonObject|null $placement;
    public string|null $retention;

    public function __construct(public JsonObject $raw)
    {
        $fields          = new MetadataFields($raw, 'DesiredClusterOrigin');
        $this->replicas  = $fields->decimal('replicas', Decimal::INT64_MAX);
        $this->placement = $fields->object('placement');
        $this->retention = $fields->text('retention');
    }
}
