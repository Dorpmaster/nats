<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

/** Deterministic read-only monitoring envelopes for the controlled TCP and Admin tests. */
final class PlacementDiagnostics
{
    /** @param array<string,mixed> $mappings */
    public static function response(string $subject, string $server = 'id', array $mappings = [], string $account = 'A'): string|null
    {
        $prefix = '$SYS.REQ.SERVER.' . $server . '.';
        $data   = match ($subject) {
            '$SYS.REQ.USER.INFO' => ['account' => $account],
            $prefix . 'JSZ', '$SYS.REQ.SERVER.PING.JSZ' => ['server_id' => $server, 'meta_cluster' => ['name' => 'CLUSTER', 'leader' => 'leader'], 'api' => ['level' => 5]],
            $prefix . 'VARZ' => ['system_account' => 'SYS', 'server_id' => $server, 'routes' => 0, 'leafnodes' => 0, 'jetstream' => ['stats' => ['api' => ['level' => 5]]]],
            $prefix . 'ACCOUNTZ' => ['server_id' => $server, 'account_detail' => ['account_name' => $account, 'is_system' => $account === 'SYS', 'imports' => $account === 'SYS' ? [] : [['subject' => '$JS.API.>', 'account' => 'SYS', 'local_subject' => '$JS.API.>', 'to' => '$JS.API.>', 'type' => 'service', 'invalid' => false]], 'complete' => true, 'expired' => false, 'leafnode_connections' => 0, 'mappings' => (object) $mappings]],
            $prefix . 'ROUTEZ' => ['server_id' => $server, 'num_routes' => 0, 'routes' => []],
            default => null,
        };
        return $data === null ? null : json_encode(['server' => ['id' => $server, 'name' => 'leader', 'domain' => 'EU'], 'data' => $data], JSON_THROW_ON_ERROR);
    }
}
