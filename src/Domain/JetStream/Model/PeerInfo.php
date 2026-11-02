<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;

/** Immutable typed view; raw retains unknown fields and original JSON tokens. */
final readonly class PeerInfo
{
    public string|null $name;
    public string|null $peerId;
    public bool|null $current;
    public bool|null $offline;
    public string|null $activeNanoseconds;
    public string|null $lag;
    public bool|null $pending;

    public function __construct(public JsonObject $raw)
    {
        $fields                  = new MetadataFields($raw, 'PeerInfo');
        $this->name              = $fields->text('name');
        $this->peerId            = $fields->text('peer');
        $this->current           = $fields->boolean('current');
        $this->offline           = $fields->boolean('offline');
        $this->activeNanoseconds = $fields->decimal('active', Decimal::INT64_MAX);
        $this->lag               = $fields->decimal('lag');
        $this->pending           = $fields->boolean('pending');
    }
}
