# JetStream replica placement (1.4.0)

`JetStreamAdmin` implements the optional `JetStreamPlacementAdminInterface`.
Existing `JetStreamAdminInterface` implementations and constructor arguments stay
compatible. These operations need **JetStream API level 5** (tested on NATS
2.15.2) and a cluster with suitable spare capacity. No `PEER.REMOVE` fallback is
used. An evacuation keeps the outgoing replica available for transferring data.

## Commands and their responses

| Public method | NATS suffix | Accepted response |
| --- | --- | --- |
| `cancelStreamMove($stream, $timeoutMs, $cancellation)` | `STREAM.CANCEL_MOVE.<stream>` | `stream_cancel_move_response` and the requested `StreamInfo` |
| `evacuateStreamPeer($stream, $peer, $timeoutMs, $cancellation)` | `STREAM.PEER.EVACUATE.<stream>` | `stream_evacuate_peer_response`, `success=true` |
| `evacuateConsumerPeer($stream, $consumer, $peer, $timeoutMs, $cancellation)` | `CONSUMER.PEER.EVACUATE.<stream>.<consumer>` | `consumer_evacuate_peer_response`, `success=true` |
| `evacuateServer($peer, $peerId, $timeoutMs, $cancellation)` | `SERVER.EVACUATE` | `meta_server_evacuate_response`, `success=true`; system account |

Commands and their bounded observers require the optional
`JetStreamConnectionBoundTransportInterface`. Admission and observation INFO
requests also use removable connection-bound frames; loss of the connection
returns an error without switching to replayable requests.

All type names have the `io.nats.jetstream.api.v1.` prefix. Timeout and
`Amp\Cancellation` arguments are optional; the default command budget is 2000 ms.
The evacuation methods return immutable `PlacementAck` with `operation`, `targets`
and the exact JSON `response`. This confirms **command acceptance**. It does not
confirm the final peer group or that data is available at the new placement.

Original scalar types, stream/consumer names, peer identifiers and timeout
values are validated before requests, including calls from PHP files without
`strict_types`. Numeric or boolean identifiers are not converted to strings.
The concrete mutation methods accept `mixed` internally to perform this check
before PHP can coerce an argument; the optional interface retains typed contracts. `peer` is a concrete
server name or NATS peer ID; whitespace, controls and wildcard identifiers are
rejected. Stream/consumer evacuation sends exactly `{"peer":"n2"}`. CANCEL_MOVE
sends `{}`. The system operation accepts exactly one identifier: `peer: 'n2'`
sends `{"peer":"n2"}`; `peerId: 'abcd1234'` sends `{"peer_id":"abcd1234"}`.
Neither identifier is silently preferred if both are supplied.

## Confirm the target and observe evacuation

```php
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamPlacementAdminInterface;

if (!$admin instanceof JetStreamPlacementAdminInterface) {
    throw new RuntimeException('This Admin has no placement capability');
}
$before = $admin->getStreamInfo('ORDERS');
$peer = $before->getCluster()->replicas[0]->name;
$ack = $admin->evacuateStreamPeer('ORDERS', $peer, timeoutMs: 5000);
$after = $admin->waitForStreamEvacuation(
    'ORDERS', $peer, expectedReplicas: 3, timeoutMs: 20000,
);
```

Changing commands require the native `$JS.API` namespace. Custom/domain prefixes
and service aliases are rejected locally: an INFO response alone cannot certify
that the changing subject reaches the same resource. Admission verifies account
INFO and resource INFO, including identity and dynamic `_nats.level` metadata;
both must establish API level >=5. `Feature::PLACEMENT_MANAGEMENT` is advisory;
UNKNOWN alone is insufficient.

Before the command, connection-bound `$SYS.REQ.USER.INFO`,
`$SYS.REQ.SERVER.<connected-server-id>.VARZ` and `ACCOUNTZ` reads establish the
native API ingress. Permissions must allow complete ACCOUNTZ diagnostics for
**the authenticated account and its system account**, in addition to account
INFO, resource INFO and the changing command. VARZ identifies the system account.
The standard unchanged `$JS.API.>` service import into that account is supported;
matching subject mappings or other imports in either account are rejected.
Only the selected changing command and the INFO subjects actually used for its
admission are checked. A mapping for PEER.EVACUATE therefore does not prevent a
native CANCEL_MOVE; a mapping of that CANCEL_MOVE or its STREAM.INFO still does.
The system account's identity must be confirmed. Incomplete diagnostics and
configured/active leaf or gateway ingress also stop the command before writing.
Unrelated mappings remain supported, including numeric-looking property names.
When exporting monitoring services, export/import only the required server
VARZ/ACCOUNTZ endpoints; retain the server's native USER.INFO service.

This proof covers the API ingress on the original server, not data-subject
routing or arbitrary remote aliases. The ordinary in-cluster native JetStream API
and its internal Raft forwarding remain supported, including unavailable
replicas during an unfinished transition. Route configuration must remain stable
between admission and the write. Each admission shares the command's deadline;
choose a budget appropriate to monitoring latency.

`waitForStreamEvacuation(stream, peerName, expectedReplicas, timeoutMs,
cancellation)` polls only STREAM.INFO. Completion requires an elected leader,
no `cluster.desired`, the expected number of distinct current peers, no pending
or offline replica, no known replication lag and absence of the evacuated server
name. The stream configuration must retain the expected replica count. Supply
**the server name** to observation methods, including when the command used a
peer ID: obtain the matching name from the pre-command N2 peer metadata.

For an individual consumer:

```php
$ack = $admin->evacuateConsumerPeer('ORDERS', 'WORKERS', 'n2', timeoutMs: 5000);
$consumer = $admin->waitForConsumerEvacuation(
    'ORDERS', 'WORKERS', 'n2', expectedReplicas: 3, timeoutMs: 20000,
);
```

The consumer must have eligible replacement peers in its stream's group. The
observer polls only CONSUMER.INFO and checks the actual consumer peer group;
consumers with an inherited replica count remain supported. N2 `ClusterInfo`,
`desired`, `origin`, `status`, peer IDs, lag and raw snapshots remain available
for progress inspection. A missing `desired` alone is insufficient for completion.
Use an independent client to read previously stored data and publish/read a new
control message after observing placement.

## Cancel a reconfiguration explicitly

CANCEL_MOVE also rolls back unfinished **replica-count and retention changes**.
It can therefore undo more than a move. Keep a stable original INFO before your
administration tooling starts the transition. Inspect the pending transition
and its `cluster.desired.origin` before explicitly cancelling:

```php
// $original was captured before the scale/move/retention change began.
$transition = $admin->getStreamInfo('ORDERS');
$accepted = $admin->cancelStreamMove('ORDERS', timeoutMs: 5000);
$restored = $admin->waitForStreamRollback(
    $transition, $original, timeoutMs: 20000,
);
```

The returned command response is a StreamInfo from the CANCEL_MOVE response,
not a separate INFO. `waitForStreamRollback()` first validates the saved original
against the transition's origin. It then polls only INFO until the stream has
its original replica count, placement and retention, the exact original server
peer set and a settled/current group. Peer identities are compared as a set of
exact strings: `"001"`, `"0001"` and `"1"` are different servers. Leader/follower
ordering does not affect equality. It requires both snapshots to establish
that baseline. The exact nanosecond `created` value must be present and agree
across original, transition and every observed snapshot. Domain and an available
original `raft_group` must also agree. A recreated stream, missing identity or
changed group produces a controlled error even if its name/configuration/peers
match. A leader change within the original group remains valid. Placement strings
are compared exactly (`"01"` differs from `"1"`); JSON object key order and tag
ordering are irrelevant. An absent `config.placement` remains valid; a present
value must be a JSON object. Arrays, booleans, strings, numbers and explicit null
are rejected with `config.placement` in the error. Invalid original placement
fails before the first INFO, and invalid observed placement fails immediately.
It does not reconstruct unknown original peers from `desired`
or accept disappearance of `desired` as proof. Consumer peer sets may differ
after rollback. The server's error when no reconfiguration is active is returned
with its original code, err_code and description.

## System-account evacuation

```php
// Construct systemAdmin with an explicitly authenticated system-account Client.
$ack = $systemAdmin->evacuateServer(peer: 'n2', timeoutMs: 5000);
// Alternative identifier: evacuateServer(peerId: $peerId, timeoutMs: 5000).
```

This explicitly invoked method evacuates assets **across accounts**. It requires
the default `$JS.API` namespace, system diagnostic access to
`$SYS.REQ.SERVER.<connected-server-id>.JSZ` and `$SYS.REQ.SERVER.PING.JSZ`,
and SERVER.EVACUATE permission. The first diagnostic establishes the current
cluster/domain; the second uses `leader_only=true` and an exact leader/domain
filter.
The diagnostic response must identify the meta leader and establish its API level
5. An ordinary account without these privileges fails before SERVER.EVACUATE.
A remote/custom system namespace cannot be certified by this diagnostic and is
rejected locally. Stream/consumer changing methods likewise require the native default API route.

A successful system response does not mean every account has converged. Observe
each affected stream/consumer through clients with access to those accounts and
check their data independently. Evacuating a server does not disconnect the
Client or itself stop the NATS process.

## Deadlines, cancellation and uncertain results

Each command has one monotonic deadline covering admission and the write/reply.
A synchronous deadline check also runs at the queued frame's write boundary,
even if producer work delays event-loop timers. Cancellation before writing
withdraws the queued command and its inbox. A changing command is bound to the
original connection and gets one attempt; reconnect never retries it. Old
retryable Core publication behavior is unchanged.

```php
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamPlacementException;

try {
    $ack = $admin->evacuateStreamPeer('ORDERS', 'n2');
} catch (JetStreamPlacementException $error) {
    $operation = $error->getOperation();
    $targets = $error->getTargets();
    if ($error->isOutcomeUnknown()) {
        // Inspect INFO; do not infer failure or blindly issue another command.
    }
    $code = $error->getApiCode();
    $errCode = $error->getErrorCode();
    $cause = $error->getPrevious();
}
```

A lost reply, malformed confirmation or intermediary 408/504 after an attempted
write may leave the outcome unknown. Known pre-write failures remain definite.
Only a JetStream 4xx rejection with `err_code` **and the exact expected response
type** (excluding timeout 408) keeps `unknown=false`. A foreign or missing response
type after writing keeps `unknown=true`, while retaining its codes and cause. Server codes/descriptions and the original exception chain are
preserved. Observer timeouts do not undo an accepted command; all observer
retries are read-only INFO calls. Observer calls have their own explicit budget
and capture one connection generation for the entire invocation. A reconnect
between polls fails before any INFO on the replacement connection; it never
silently continues against another account/domain.

The built-in transport rejects the four placement commands through ordinary
`request()`, `requestJson()` and `publishRequest()` before creating an inbox or
queueing a frame. This guard checks both the protected suffix and the final
`$JS.API.<command>` or `$JS.<domain>.API.<command>` wire subject, independently
of how it is split between constructor prefix and request suffix (for example,
`$JS` plus `API.SERVER.EVACUATE`, or `$JS.EU` plus
`API.STREAM.CANCEL_MOVE.ORDERS`). Named-domain INFO requests remain supported.
This transport guard does not add domain-command admission to Admin: changing
Admin operations still require the native `$JS.API` route and its diagnostics.
Use the public placement Admin, whose bound single-attempt path
performs admission. Low-level `requestJsonOnConnection()` preserves API error
envelopes in an API-exception subtype so the operation can authenticate its
response type; third-party transports that throw an untyped API error cannot
prove a definitive rejection after writing.

Run `make integration-jetstream-placement` for old-version admission and the
isolated seven-node 2.15.2 profile. Use `integration-jetstream-placement-cluster`
for the cluster only and `integration-jetstream-placement-tcp` for controlled
TCP write/cancellation/reconnect regressions. The Python runner's `--output`
option records the command response, INFO before/after and independent reads
separately. The held rollback test pauses peers and releases them after the
public CANCEL_MOVE response. It also tests exact tags on the same peer group,
a leader change and deletion/recreation with the same name and peers. All fixtures
are removed on exit. Run the runner with `--numeric-peers --held-only` to repeat
the rollback/leader checks with distinct numeric-looking server names.

Protocol references: [ADR-62](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-62.md),
[NATS 2.15.2 API](https://github.com/nats-io/nats-server/blob/v2.15.2/server/jetstream_api.go).
