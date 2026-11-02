<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Closure;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;

/** @internal Certify the native API ingress, including the built-in import into the system account. */
final readonly class PlacementRoute
{
    /** @param Closure(string,array):JsonObject $read */
    public function __construct(private Closure $read)
    {
    }

    /** @param list<string> $subjects */
    public function verify(string|null $serverId, array $subjects, bool $systemOnly): void
    {
        $this->token($serverId);
        $user    = $this->response('USER.INFO', [], $serverId);
        $account = (new MetadataFields($user, 'route.user'))->text('account');
        $this->token($account);
        $prefix = 'SERVER.' . $serverId . '.';
        $varz   = $this->response($prefix . 'VARZ', [], $serverId);
        $system = (new MetadataFields($varz, 'route.varz'))->text('system_account');
        $this->token($system);
        if ($systemOnly && $account !== $system) {
            $this->deny('system evacuation requires the authenticated system account');
        }
        $value = $varz->toArray();
        if ($varz->get('server_id') !== $serverId || !is_int($value['jetstream']['stats']['api']['level'] ?? null) || $value['jetstream']['stats']['api']['level'] < 5 || ($value['leafnodes'] ?? null) !== 0) {
            $this->deny('native API or server topology is not established');
        }
        foreach (['leaf', 'gateway'] as $kind) {
            $exit = $value[$kind] ?? [];
            if (!is_array($exit) || ($exit['port'] ?? 0) !== 0 || ($exit['remotes'] ?? []) !== [] || ($exit['gateways'] ?? []) !== [] || ($exit['name'] ?? '') !== '') {
                $this->deny('leaf or gateway ingress cannot be certified');
            }
        }
        // JetStream imports the default API into SYS without changing its subject. Other
        // imports/mappings must not redirect that request either before or after this hop.
        $detail = $this->account($prefix, $account, $serverId);
        $this->routes($detail, $subjects, $account, $system);
        if ($account !== $system) {
            $detail = $this->account($prefix, $system, $serverId);
            $this->routes($detail, $subjects, $system, $system);
        }
        if ($detail->get('is_system') !== true) {
            $this->deny('native system account identity is not established');
        }
    }

    private function account(string $prefix, string $account, string $serverId): JsonObject
    {
        $raw    = $this->response($prefix . 'ACCOUNTZ', ['account' => $account], $serverId);
        $detail = (new MetadataFields($raw, 'route.accountz'))->object('account_detail');
        if ($raw->get('server_id') !== $serverId || $detail === null || $detail->get('account_name') !== $account || $detail->get('complete') !== true || $detail->get('expired') !== false || $detail->get('leafnode_connections') !== 0) {
            $this->deny('account identity or complete configuration is not established');
        }
        return $detail;
    }

    /** @param list<string> $subjects */
    private function routes(JsonObject $detail, array $subjects, string $account, string $system): void
    {
        if ($detail->has('mappings')) {
            $map = $detail->getObject('mappings');
            if ($map === null) {
                $this->deny('invalid account mappings');
            }
            foreach (array_keys($map->toArray()) as $pattern) {
                if ($this->matches((string) $pattern, $subjects)) {
                    $this->deny('subject mapping may redirect the placement command or INFO');
                }
            }
        }
        $native = 0;
        foreach (['imports', 'exports'] as $kind) {
            if (!$detail->has($kind)) {
                continue;
            }
            $entries = $detail->get($kind);
            if (!is_array($entries) || !array_is_list($entries)) {
                $this->deny('invalid account ' . $kind);
            }
            foreach ($entries as $entry) {
                if (!$entry instanceof \stdClass || !is_string($entry->subject ?? null) || !in_array($entry->type ?? null, ['service', 'stream'], true)) {
                    $this->deny('invalid account route');
                }
                $pattern = $kind === 'imports' ? ($entry->local_subject ?? $entry->to ?? null) : $entry->subject;
                $matches = $this->matches($pattern, $subjects);
                if ($kind === 'exports') {
                    // An export authorizes inbound requests; it does not redirect this publication.
                    continue;
                }
                if ($matches) {
                    if ($account !== $system && $entry->type === 'service' && ($entry->invalid ?? null) === false && ($entry->account ?? null) === $system && $entry->subject === '$JS.API.>' && $pattern === '$JS.API.>' && ($entry->to ?? '$JS.API.>') === '$JS.API.>') {
                        $native++;
                    } else {
                        $this->deny('account import may redirect the placement command or INFO');
                    }
                }
            }
        }
        if ($account !== $system && $native !== 1) {
            $this->deny('one unchanged native JetStream API import is required');
        }
    }

    /** @param list<string> $subjects */
    private function matches(mixed $pattern, array $subjects): bool
    {
        if (!is_string($pattern) || $pattern === '') {
            $this->deny('unknown account route');
        }
        $tokens = explode('.', $pattern);
        foreach ($tokens as $index => $token) {
            if ($token === '' || ($token === '>' && $index !== count($tokens) - 1) || preg_match('/[\s\p{Cc}]/u', $token) !== 0 || ($token !== '*' && $token !== '>' && strpbrk($token, '*>') !== false)) {
                $this->deny('invalid account route pattern');
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
                return true;
            }
        }
        return false;
    }

    private function response(string $suffix, array $payload, string $serverId): JsonObject
    {
        $raw = ($this->read)($suffix, $payload);
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
        throw new JetStreamApiException(0, 'Placement route is unverified: ' . $reason);
    }
}
