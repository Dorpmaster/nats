# Release Notes — v1.3.0

## Overview

Version 1.3.0 extends JetStream administration, metadata and publishing, and fixes protocol, TLS and logging issues for applications upgrading from 1.2.0. Publication can use atomic batches or streaming fast batches with incremental confirmations.

**Release date:** 2026-11-02

Existing Core publish/subscribe and queue-group APIs remain available. New JetStream operations use optional interfaces implemented by the built-in classes.

---

## JetStream features

### Capabilities and selected stream updates

`Client::getServerInformation()` exposes the latest server identity, JetStream flag and `apiLevel` from `INFO.api_lvl`. Admin, transport and publisher expose `getCapabilities()`. Check a feature before choosing an operation:

```php
use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;

$support = $admin->getCapabilities()->check(Feature::CONSUMER_RESET);
// $support->support is Support::SUPPORTED, UNSUPPORTED or UNKNOWN.

$info = $admin->updateStream('ORDERS', new StreamConfigPatch(maxConsumers: 20));
```

Checks are advisory and make no network calls. They use the current transport's announced API level, not the Core `proto` field or a guess from the version string. Reconnect or a later INFO invalidates old snapshots. Permission, stream settings and routing still need to permit the operation.

`JetStreamControlPlaneTransport` accepts an `apiPrefix`, for example `$JS.EU.API` for a domain or an imported API subject. The default is `$JS.API`. Local INFO does not establish the remote target's capabilities, so domain/import checks return `UNKNOWN`.

`StreamConfig` and `StreamConfigPatch` expose `allowAtomicPublish`, `allowBatchPublish` and `maxConsumers`; the wire fields are `allow_atomic`, `allow_batched` and `max_consumers`. A patch also accepts `subjects` and `replicas`. `updateStream()` reads the existing configuration and changes only selected fields, preserving other settings, unknown fields and exact JSON values. Updating one limit preserves the stream's routing. Invalid `subjects`, including explicit null or numeric elements, are rejected before UPDATE; an absent field stays absent and `[]` stays an empty list.

INFO followed by UPDATE is not an atomic merge between clients: concurrent configuration writers can overwrite each other. UPDATE has one deadline and is not automatically replayed after an uncertain write or reconnect. Inspect state before retrying a timed-out update.

`JetStreamApiException::getApiCode()` retains the server's `code`; `getErrorCode()` exposes its separate `err_code`, and `getDescription()` retains the description. See [Admin](docs/jetstream-admin.md) and [capability rules](docs/jetstream-compatibility.md).

### Exact message and Admin metadata

Messages from `fetch()` and `consume()` implement the optional `JetStreamMessageMetadataInterface`. `getMetadata()` returns ACK-derived `MessageMetadata`, or null when full metadata is unavailable. Its public properties include `streamSequence`, `consumerSequence`, `deliveryCount`, `pending`, `domain`, `accountHash` and `timestamp`.

Counters and sequences are exact decimal strings. `NanoTimestamp::getRfc3339()` and `getUnixNanoseconds()` retain nanosecond precision; `toDateTimeImmutable()` truncates submicrosecond digits. Avoid converting exact values to float. `JetStreamMessageHeadersInterface::getHeaderValues()` exposes all repeated values; the existing `getHeaders()` first-value view remains available.

`getStreamInfo()` and `getConsumerInfo()` return immutable snapshots, with `getConfiguration()` and `getResponse()` providing preserved `JsonObject` values. Stream Info adds state and timestamps; Consumer Info adds delivered and ACK-floor sequences through `getDelivered()` and `getAckFloor()`, plus exact count getters such as `getPendingCount()`.

Both Info models expose `getCluster()`. Its `replicas` describe current peers, while `desired->replicas`, `desired->origin` and `desired->status` describe a reported target state. Missing desired state does not prove that a replica transition has completed. Reading these models does not refresh or change the server. See [metadata](docs/jetstream-metadata.md).

### Reset consumer delivery

The optional `JetStreamResettingAdminInterface` adds:

```php
$result = $admin->resetConsumer('ORDERS', 'C1');
$position = $result->getResetSequence();
$consumerInfo = $result->getConsumerInfo();

$replay = $admin->resetConsumer('ORDERS', 'C1', '7', timeoutMs: 2000);
```

A null position, `0` or `"0"` requests the current stream ACK floor plus one. A positive `int` or decimal string requests delivery from the first eligible stored record at or after that stream sequence. UInt64 strings remain exact, including values above PHP int; floats and booleans are rejected locally. The result contains the server-confirmed reset sequence as a decimal string and Consumer Info from the same response.

RESET changes the shared consumer for all its clients, without deleting and recreating it. Consumer sequences can start at one again. Coordinate outstanding deliveries and ACKs; reset does not restore deleted records or override server delivery-policy restrictions.

RESET makes one bounded attempt and is not replayed after reconnect. A timeout does not prove that the server did not apply it. Inspect Consumer Info and delivery state before issuing another reset. Known unsupported capability fails locally; an unknown domain/import target permits one explicit bounded attempt.

**NATS 2.14.0 service-import limitation:** in the tested cross-account import with `response: stream`, RESET was applied but its reply was not forwarded, so the caller timed out. A subsequent INFO through the import could inspect the changed state. Local/domain-prefixed RESET worked on 2.14.0, and the same import roundtrip worked on 2.15.2. See [reset semantics and limitations](docs/jetstream-admin.md).

### Atomic batch publication

The optional `JetStreamAtomicPublisherInterface::publishAtomicBatch()` accepts a nonempty ordered list of messages for one stream:

```php
$ack = $publisher->publishAtomicBatch('ORDERS', [
    ['subject' => 'orders.created', 'payload' => 'first'],
    ['subject' => 'orders.audit', 'payload' => "second\0payload"],
], timeoutMs: 5000);
```

Each entry has a concrete `subject`, binary `payload` and optional `headers`. The entire batch is validated and frozen before its first data frame. A valid final `AtomicBatchAck` confirms commitment of the whole batch; staged messages are not visible before commit.

The default `AtomicBatchCommitMode::MESSAGE` stores the final user message as the commit. `AtomicBatchOptions(commitMode: AtomicBatchCommitMode::EOB)` sends a separate empty end marker, which does not create a stored message.

If a commit may have reached the server but confirmation is lost, `AtomicBatchException::getBatchId()` and `isOutcomeUnknown()` identify the batch and uncertainty. Server codes and the original cause remain available. A timeout or intermediary error is not proof of rejection. No batch continuation or commit is replayed after reconnect; reconcile an unknown outcome before deciding to retry. See [atomic publication](docs/jetstream-publish.md).

### Streaming fast batches

The optional `JetStreamFastBatchPublisherInterface::openFastBatch()` opens a session for one stream and a declared set of concrete subjects:

```php
$session = $publisher->openFastBatch('ORDERS', ['orders.created', 'orders.audit']);
$session->publish('orders.created', 'first');
$result = $session->commit('orders.audit', 'last');
// Or publish more messages, then finish() with an empty EOB marker.
```

Messages may be persisted and visible before the session finishes. The session accepts messages one at a time, preserves binary payloads and repeated headers, and bounds outstanding messages and bytes. `FastBatchOptions` controls flow credit and limits; the default permits two unconfirmed windows, with a maximum of three. `cancel()` stops future sends without rolling back stored messages.

On failure, catch `FastBatchException` and inspect `getResult()`. `FastBatchResult` exposes `getBatchId()`, `getLastSentSequence()`, `getLastAcknowledgedSequence()`, `getUnknownRanges()`, `getProblemSequence()` and `getFinalAck()`. The last sent index means handed to the writer, not confirmed persistence. A partial refusal has `isComplete()=false` and retains server codes and the confirmed prefix.

The `gap=fail` mode stops on sequence gaps or message errors, withdraws unsent frames and waits for the final prefix acknowledgement within the original deadline. Without that final reply, prior cumulative ACKs remain the known boundary. Lost connection or cancellation does not replay the old session. Reconcile unknown ranges before resending: some messages may already be stored. An empty unknown range can still accompany `isOutcomeUnknown()=true` if all messages were ACKed but final commit confirmation was lost. Deduplication can validly yield a final stream `seq=0` with a positive batch count. See [streaming publication and partial results](docs/jetstream-fast-batch.md).

---

## Security and fixes

- **Command injection:** unsafe command fields and JetStream resource names now fail locally with `InvalidArgumentException` before a request, inbox or write. Validation covers outgoing PUB/HPUB, SUB/UNSUB and direct JetStream paths, including trailing newlines. Valid resource names containing `@`, `+`, hyphens or underscores, subscription patterns, existing Core wildcard rules and binary payloads are preserved.
- **CONNECT logging:** password/token values, credential-bearing objects, raw frames and payloads are excluded before records reach a PSR-3 logger. This applies to initial connection, reconnect and error paths. If DEBUG was enabled previously, the fix protects newly written records; existing log files are unchanged.
- **No-wait pulls:** `fetch(noWait: true)` now waits for replies to its pull request. It returns available messages, a partial batch or an empty result on normal server completion. `expiresMs` is the local response deadline; if completion is missing, `JetStreamTimeoutException` is raised even after partial delivery. It is not sent as a server `expires` alongside `no_wait`. The existing `noWait: false` behavior is retained.
- **MSG/HMSG parsing:** empty MSG, fragmented frames, status-only HMSG and service statuses are handled without shifting frame boundaries. Heartbeats are not delivered as application messages. Ordinary header values containing `NATS/`, URLs or several colons, repeated values and binary payloads remain intact; original body sizes support `maxBytes` accounting.
- **HPUB sizes and bytes:** changing a header bag before serialization produces current, matching sizes. Enqueueing freezes the frame, and queue accounting, socket writes and ordinary retries use those same bytes. Later header changes do not mutate an already queued frame.
- **Delivery counts:** `getDeliveryCount(): ?int` now comes from classic or expanded ACK subjects. `Nats-Num-Delivered` remains a user header and cannot influence the count. Invalid or unavailable counts yield null; DROP_NEW sends automatic TERM only for a dropped message with a known count greater than five.
- **Repeated INFO and reconnect:** later INFO updates server/discovery information without another CONNECT or handshake PING, readiness reset or subscription replay. Every new connection still performs its own handshake; an old reader cannot close a replacement connection.
- **TLS:** `allowSelfSigned=true` now enables the actual PHP stream option without lowering TLS security. With `verifyPeer=true`, expiry and server-name checks remain enabled, along with existing CA, minimum-version, ALPN and client-certificate settings. Reconnect/failover applies the setting again. Sockets unable to apply `allowSelfSigned=true` raise `ConnectionException`, and failed transports close before retry. See [TLS](docs/tls.md).

Docker test isolation and cleanup have also been restored. See [CHANGELOG.md](CHANGELOG.md) for the release summary.

---

## Compatibility

PHP remains `^8.5`, with the existing `ext-event` and `ext-pcntl` requirements. The only dependency floor raised by this release is **`amphp/byte-stream ^2.1.3`**; other package requirements remain unchanged. Version 2.1.3 includes fixes for stalled TLS reads with event-loop drivers and zero-byte writes to broken connections.

Ordinary atomic commit requires JetStream **API level 2**. Atomic EOB, consumer RESET and fast batches require **level 4**. The tested server matrix is:

| NATS Server | Announced `api_lvl` | Atomic MESSAGE | Atomic EOB | Consumer RESET | Fast batch |
| --- | ---: | --- | --- | --- | --- |
| 2.12.6 | 3 | Yes | No | No | No |
| 2.14.0 | 4 | Yes | Yes | Yes, with the service-import limitation above | Yes |
| 2.15.2 | 5 | Yes | Yes | Yes | Yes |

Support checks use announced API levels, not this version table as a version-string heuristic. Missing or stale information yields `UNKNOWN`. Protocol support does not grant account permissions or enable a stream flag. Atomic publication needs **`allow_atomic=true`**; fast batches need **`allow_batched=true`** and work with `allow_atomic=false`.

**Both batch modes require diagnostic rights before data publication.** In addition to stream INFO and subject-to-stream discovery, the publisher requires `$SYS.REQ.USER.INFO` and `$SYS.REQ.SERVER.<id>.VARZ`, `ACCOUNTZ` and `ROUTEZ` access for the connected server and every discovered cluster peer. See the [example monitoring grants](tests/Support/atomic-monitoring.conf).

Both modes currently verify the default `$JS.API` scope. A separate Admin API prefix does not select their target. They require an isolated, fully checked cluster with no configured or active leaf/gateway exits and no matching account subject mappings, imports or exports for batch subjects. Unrelated mappings are permitted. Keep routing, topology and stream configuration stable for the duration of publication. If diagnostics, required support or a route cannot be established, the call fails before any batch data frame; there is no unchecked fallback. These extra monitoring grants are not required by ordinary single-message publication.

Existing Admin, publisher, message and client interfaces retain their contracts. Third-party implementations can continue existing operations; new UPDATE, RESET and batch operations require their corresponding optional transport/client capabilities. In particular, mutations require single-attempt support and cannot silently use a replayable transport. Known Admin JSON fields are validated strictly, while raw snapshots preserve unknown fields and exact tokens. See [compatibility contracts](docs/jetstream-compatibility.md).

---

## Upgrade

1. Ensure PHP satisfies `^8.5` and the required extensions are enabled. Update the driver and byte-stream together. If your application pins byte-stream to `2.1.2` or earlier, change that constraint as part of the upgrade:

   ```bash
   composer require 'dorpmaster/nats:1.3.0' 'amphp/byte-stream:^2.1.3' --with-all-dependencies
   ```

   Run this command after 1.3.0 is published and review the resulting application lockfile.

2. Check the selected server's announced API level and stream flags before enabling the new JetStream operations. For either batch mode, configure the diagnostic grants and confirm that the topology/routing restrictions above fit your deployment. Ordinary single-message publication remains available without those grants.
3. Review no-wait pull callers for `JetStreamTimeoutException` and resource/command inputs for local validation errors. Remove any reliance on a user `Nats-Num-Delivered` header controlling delivery counts. Verify INFO/reconnect and TLS behavior in your deployment, especially if `allowSelfSigned` was enabled.
4. Handle uncertain UPDATE/RESET and atomic commit outcomes by checking server state. For fast batches, use the confirmed boundary and unknown ranges rather than automatically replaying the session. Coordinate resets with other clients sharing the consumer.
5. Use exact decimal counters and timestamp getters where precision matters. Check historical DEBUG logs separately: this release changes new logging, not previously stored records.
