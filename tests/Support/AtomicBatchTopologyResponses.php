<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

/** Synthetic monitoring responses for unit and controlled TCP protocol tests only. */
final class AtomicBatchTopologyResponses
{
    public static function payload(string $subject, string $serverId): string|null
    {
        if (!str_starts_with($subject, '$SYS.REQ.')) {
            return null;
        }
        $data = match (true) {
            $subject === '$SYS.REQ.USER.INFO' => ['account' => 'HUB'],
            str_ends_with($subject, '.VARZ') => ['server_id' => $serverId, 'routes' => 0, 'leafnodes' => 0, 'jetstream' => ['stats' => ['api' => ['level' => 5]]]],
            str_ends_with($subject, '.ACCOUNTZ') => ['server_id' => $serverId, 'account_detail' => ['account_name' => 'HUB', 'complete' => true, 'expired' => false, 'leafnode_connections' => 0]],
            str_ends_with($subject, '.ROUTEZ') => ['server_id' => $serverId, 'num_routes' => 0, 'routes' => []],
            default => throw new \LogicException('Unexpected monitoring request'),
        };
        return json_encode(['server' => ['id' => $serverId], 'data' => $data], JSON_THROW_ON_ERROR);
    }
}
