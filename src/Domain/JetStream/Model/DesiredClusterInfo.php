<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

/** Immutable typed view; raw retains unknown fields and original JSON tokens. */
final readonly class DesiredClusterInfo
{
    public NanoTimestamp|null $created;
    public string|null $name;
    /** @var list<DesiredPeerInfo>|null */
    public array|null $replicas;
    public DesiredClusterOrigin|null $origin;
    public DesiredClusterStatus|null $status;

    public function __construct(public JsonObject $raw)
    {
        $fields         = new MetadataFields($raw, 'DesiredClusterInfo');
        $this->created  = $fields->timestamp('created');
        $this->name     = $fields->text('name');
        $this->replicas = ($items = $fields->objects('replicas')) === null ? null : array_map(static fn (JsonObject $item): DesiredPeerInfo => new DesiredPeerInfo($item), $items);
        $this->origin   = ($object = $fields->object('origin')) === null ? null : new DesiredClusterOrigin($object);
        $this->status   = ($object = $fields->object('status')) === null ? null : new DesiredClusterStatus($object);
    }
}
