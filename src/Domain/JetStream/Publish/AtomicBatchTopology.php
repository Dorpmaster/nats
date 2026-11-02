<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Closure;
use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;

/** @internal Fail closed unless monitoring confirms an isolated, untransformed account route. */
final readonly class AtomicBatchTopology
{
    /** @param Closure(string, array):JsonObject $read Connection-bound read under the batch deadline. */
    public function __construct(private Closure $read)
    {
    }

    /** @param list<string> $subjects */
    public function verify(string|null $serverId, array $subjects, Feature $feature): void
    {
        $this->token($serverId);
        $user    = $this->response('$SYS.REQ.USER.INFO', [], $serverId);
        $account = $user->get('account');
        $this->token($account);
        $pending = [$serverId];
        $visited = [];
        while ($pending !== []) {
            $id = array_pop($pending);
            $this->token($id);
            if (isset($visited[$id])) {
                continue;
            }
            if (count($visited) >= 128) {
                $this->deny('cluster inventory exceeds 128 servers');
            }
            $visited[$id] = true;
            $prefix       = '$SYS.REQ.SERVER.' . $id . '.';
            $varz         = $this->response($prefix . 'VARZ', [], $id)->toArray();
            if (($varz['server_id'] ?? null) !== $id || !is_int($varz['routes'] ?? null) || $varz['routes'] < 0 || ($varz['leafnodes'] ?? null) !== 0) {
                $this->deny('server identity or topology is not established');
            }
            $level = $varz['jetstream']['stats']['api']['level'] ?? null;
            if (!is_int($level) || $level < $feature->minimumApiLevel()) {
                $this->deny('cluster member API level does not support the selected mode');
            }
            // Exclude configured as well as active exits; no data probe is sent through them.
            foreach (['leaf', 'gateway'] as $kind) {
                $exit = $varz[$kind] ?? [];
                if (!is_array($exit) || ($exit['port'] ?? 0) !== 0 || ($exit['remotes'] ?? []) !== [] || ($exit['gateways'] ?? []) !== [] || ($exit['name'] ?? '') !== '') {
                    $this->deny('leaf or gateway routing cannot be certified');
                }
            }
            $accountz = $this->response($prefix . 'ACCOUNTZ', ['account' => $account], $id);
            if ($accountz->get('server_id') !== $id || ($detail = $accountz->getObject('account_detail')) === null) {
                $this->deny('account monitoring is unavailable');
            }
            if ($detail->get('account_name') !== $account || $detail->get('complete') !== true || $detail->get('expired') !== false || $detail->get('leafnode_connections') !== 0) {
                $this->deny('account identity or isolation is not established');
            }
            $this->accountRoutes($detail, $subjects);
            $routez = $this->response($prefix . 'ROUTEZ', [], $id)->toArray();
            $routes = $routez['routes'] ?? null;
            if (($routez['server_id'] ?? null) !== $id || !is_array($routes) || !array_is_list($routes) || ($routez['num_routes'] ?? null) !== count($routes) || count($routes) !== $varz['routes']) {
                $this->deny('cluster inventory is incomplete or changed during verification');
            }
            foreach ($routes as $route) {
                $remote = is_array($route) ? ($route['remote_id'] ?? null) : null;
                $this->token($remote);
                if (!isset($visited[$remote])) {
                    $pending[] = $remote;
                }
            }
        }
    }

    /** @param list<string> $subjects */
    private function accountRoutes(JsonObject $detail, array $subjects): void
    {
        if ($detail->has('mappings')) {
            $mappings = $detail->getObject('mappings');
            if ($mappings === null) {
                $this->deny('invalid account mappings');
            }
            foreach (array_keys($mappings->toArray()) as $pattern) {
                // JSON names are strings; PHP array keys coerce canonical integers, but the cast restores them exactly.
                $this->exclude((string) $pattern, $subjects, 'subject mapping');
            }
        }
        foreach (['imports', 'exports'] as $kind) {
            if (!$detail->has($kind)) {
                continue;
            }
            $entries = $detail->get($kind);
            if (!is_array($entries) || !array_is_list($entries)) {
                $this->deny('invalid account ' . $kind);
            }
            foreach ($entries as $entry) {
                if (!$entry instanceof \stdClass) {
                    $this->deny('invalid account route');
                }
                // Monitoring reports the local side of imports in local_subject (and deprecated to).
                $pattern = $kind === 'exports' ? ($entry->subject ?? null) : ($entry->local_subject ?? $entry->to ?? null);
                $this->exclude($pattern, $subjects, 'account ' . $kind);
            }
        }
    }

    /** @param list<string> $subjects */
    private function exclude(mixed $pattern, array $subjects, string $reason): void
    {
        if (!is_string($pattern) || $pattern === '') {
            $this->deny('unknown ' . $reason);
        }
        $tokens = explode('.', $pattern);
        foreach ($tokens as $index => $token) {
            if ($token === '' || ($token === '>' && $index !== count($tokens) - 1) || preg_match('/[\s\p{Cc}]/u', $token) !== 0 || ($token !== '*' && $token !== '>' && strpbrk($token, '*>') !== false)) {
                $this->deny('invalid ' . $reason . ' pattern');
            }
        }
        foreach ($subjects as $subject) {
            $parts   = explode('.', $subject);
            $matches = true;
            foreach ($tokens as $index => $token) {
                if (!isset($parts[$index]) || ($token !== '*' && $token !== '>' && $token !== $parts[$index])) {
                    $matches = false;
                    break;
                }
                if ($token === '>') {
                    break;
                }
            }
            if ($matches && (count($parts) === count($tokens) || end($tokens) === '>')) {
                $this->deny($reason . ' may redirect or copy a batch subject');
            }
        }
    }

    private function response(string $subject, array $payload, string $serverId): JsonObject
    {
        $raw = ($this->read)($subject, $payload);
        if ($raw->getObject('server')?->get('id') !== $serverId || ($data = $raw->getObject('data')) === null) {
            $this->deny('monitoring response identity is not established');
        }
        return $data;
    }

    private function token(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[^\s\p{Cc}.*>]+\z/u', $value) !== 1) {
            $this->deny('missing monitoring identity');
        }
    }

    private function deny(string $reason): never
    {
        throw new JetStreamApiException(0, 'Atomic route is unverified: ' . $reason);
    }
}
