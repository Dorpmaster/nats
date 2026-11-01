<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

/** Immutable typed view; raw retains unknown fields and original JSON tokens. */
final readonly class ClusterInfo
{
    public string|null $name;
    public string|null $raftGroup;
    public string|null $leader;
    public NanoTimestamp|null $leaderSince;
    public bool|null $systemAccount;
    public string|null $trafficAccount;
    /** @var list<PeerInfo>|null */
    public array|null $replicas;
    public DesiredClusterInfo|null $desired;

    public function __construct(public JsonObject $raw)
    {
        $fields               = new MetadataFields($raw, 'ClusterInfo');
        $this->name           = $fields->text('name');
        $this->raftGroup      = $fields->text('raft_group');
        $this->leader         = $fields->text('leader');
        $this->leaderSince    = $fields->timestamp('leader_since');
        $this->systemAccount  = $fields->boolean('system_account');
        $this->trafficAccount = $fields->text('traffic_account');
        $this->replicas       = ($items = $fields->objects('replicas')) === null ? null : array_map(static fn (JsonObject $item): PeerInfo => new PeerInfo($item), $items);
        $this->desired        = ($object = $fields->object('desired')) === null ? null : new DesiredClusterInfo($object);
    }
}
