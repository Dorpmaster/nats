<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

/** Immutable typed view; raw retains unknown fields and original JSON tokens. */
final readonly class DesiredPeerInfo
{
    public string|null $name;
    public string|null $peerId;
    public bool|null $offline;

    public function __construct(public JsonObject $raw)
    {
        $fields        = new MetadataFields($raw, 'DesiredPeerInfo');
        $this->name    = $fields->text('name');
        $this->peerId  = $fields->text('peer');
        $this->offline = $fields->boolean('offline');
    }
}
