# Changelog

All notable changes to this project are documented in this file.

## [1.3.0] - 2026-11-02

### Added

- JetStream capability checks based on the current connection's `INFO.api_lvl`, configurable Admin API prefixes, structured server `err_code` access, and optional stream settings for atomic/batched publication and consumer limits. `updateStream()` patches selected fields while preserving the rest of the stream configuration, including exact JSON values.
- Exact JetStream message metadata and immutable Stream/Consumer Info snapshots: decimal counters and sequences, nanosecond timestamps, repeated header values, and separate current and desired cluster/replica information.
- `resetConsumer()` to resume from the stream ACK floor plus one or an explicit UInt64 stream sequence, returning the server-confirmed reset position and Consumer Info. RESET and UPDATE use single-attempt requests that are not replayed after reconnect; a timeout may require checking server state before retrying.
- `publishAtomicBatch()` to commit an ordered batch to one stream as a whole, with ordinary message commit or a separate EOB marker. Lost commit confirmation exposes the batch ID and an unknown-outcome flag. Publication requires confirmed server/stream support, diagnostic access and a verified route before any batch data is sent.
- `openFastBatch()` for streaming publication with bounded message/byte credit and cumulative acknowledgements. Messages can be visible before completion. Partial failures report the confirmed prefix, problem index and unknown message ranges; final acknowledgements settle that prefix, including fully deduplicated batches. Unsent frames are withdrawn on cancellation, expiry or server refusal, and the batch is not replayed after reconnect.

### Changed

- Raised the minimum `amphp/byte-stream` version to `2.1.3` (`^2.1.3`, allowing `>=2.1.3 <3.0.0`) to prevent stalled TLS reads with non-select event-loop drivers and report an error when writing to a disconnected peer makes `fwrite()` return zero bytes. See the [AMPHP Byte Stream 2.1.3 release notes](https://github.com/amphp/byte-stream/releases/tag/v2.1.3). PHP remains `^8.5`; other dependency and extension requirements are unchanged. Applications locking `amphp/byte-stream` to `2.1.2` or earlier must update it together with the driver when upgrading to `1.3.0`.

### Security

- Prevent NATS command injection through outgoing protocol fields. Validate JetStream resource names before requests or inbox creation and reject unsafe PUB/HPUB subjects and reply-to values, plus SUB/UNSUB subject, SID and queue-group fields where used, including trailing newlines. Valid JetStream names such as `ORDERS@EU`, `ORDERS+EU` and `WORKER@EU`, subscription patterns, existing Core wildcard contracts and binary payloads remain supported.
- Prevent password/token leakage through DEBUG logging of CONNECT. Handshake, reconnect, socket I/O and error diagnostics now pass safe metadata to PSR-3 loggers instead of credential-bearing objects, raw frames or payloads. This protects new log records; existing logs are not rewritten.

### Fixed

- `fetch(noWait: true)` now waits for asynchronous replies and returns a partial or empty batch on the server's terminating response. `expiresMs` bounds local waiting; a missing completion response raises `JetStreamTimeoutException`, even after partial delivery. Heartbeats, status-only HMSG, server errors and byte-limit completion are handled without returning service frames as messages.
- Parse empty MSG payloads and fragmented MSG/HMSG without losing frame boundaries. Preserve HMSG status metadata and ordinary headers containing `NATS/`, URLs or multiple colons, including repeated values in order, binary payloads and original body sizes.
- Keep Core and JetStream HPUB lengths consistent with the current mutable header bag. Queue accounting, socket writes and ordinary write retries use the same serialized frame captured at enqueue time.
- Derive `getDeliveryCount()` from classic or expanded JetStream ACK subjects, independently of user `Nats-Num-Delivered` headers and opaque extension tokens. Missing or invalid counts remain `null`; automatic DROP_NEW termination applies only to dropped messages with a known count greater than five.
- Handle repeated INFO as server/discovery updates without another CONNECT, handshake PING, readiness transition or subscription replay. A new transport still performs its own handshake, and a delayed reader from an old connection cannot close its replacement.
- Apply TLS `allowSelfSigned` to PHP's `allow_self_signed` option without lowering the security level. With `verifyPeer=true`, certificate validity and server-name checks remain enabled; CA, minimum TLS, ALPN and mTLS settings are preserved across reconnect/failover. Unsupported sockets fail explicitly, and failed transports are closed before retry.
- Validate known Admin JSON fields against their original token types, including numeric values outside PHP int. Preserve absent versus empty stream subjects, legacy array-only transport compatibility and exact configuration snapshots; malformed responses cannot silently change stream routing during an update.
- Restore isolated Docker test setup and reliable cleanup.

For full details and upgrade guidance, see [RELEASE_NOTES_1.3.0.md](RELEASE_NOTES_1.3.0.md).

## [1.2.0] - 2026-03-27

### Added

- Queue group support for Core NATS subscriptions.
- Backward-compatible `subscribe(string $subject, \Closure $closure, ?string $queueGroup = null): string` API.
- Integration coverage for queue group load balancing and reconnect replay.

### Changed

- Subscription metadata now stores queue group information.
- Reconnect replay restores queue subscriptions with their queue group.
- Buffered subscribe path now preserves queue group metadata before READY.

### Notes

For full details, see [RELEASE_NOTES_1.2.0.md](RELEASE_NOTES_1.2.0.md).

## [1.1.1] - 2026-03-23

### Changed

- Refactored client state transition orchestration to separate state commit from lifecycle side effects.
- State change events are now dispatched only after internal lifecycle effects have been applied.
- `CONNECTED` notifications now have post-handshake semantics.

### Fixed

- Prevented listeners from observing partially-applied lifecycle state during `CONNECTED`, `RECONNECTING`, and `DRAINING` transitions.
- Improved lifecycle consistency for reconnect, drain, write buffer, ping service, and inbound scheduler orchestration.

### Notes

For full details, see [RELEASE_NOTES_1.1.1.md](RELEASE_NOTES_1.1.1.md).

## [1.1.0] - 2026-03-21

### Breaking

- Removed unused `ClientConfiguration::getReconnectServers()` from the public configuration contract.
- Removed the `reconnectServers` named argument from `ClientConfiguration`.

### Changed

- Reconnect seed servers are now configured only through `servers: list<ServerAddress>`.
- Example workers keep using `NATS_RECONNECT_SERVERS`, but map it directly into `servers`.

### Internal

- Removed dead configuration state that was not connected to runtime reconnect/failover logic.
- Simplified `ClientConfiguration` tests to cover the single effective reconnect server path.

For full details, see [RELEASE_NOTES_1.1.0.md](RELEASE_NOTES_1.1.0.md).

## [1.0.3] - 2026-03-12

### Added

- Bounded inbound dispatch scheduler for application-level `MSG/HMSG` callbacks.
- New client configuration limits: `maxInboundDispatchConcurrency` and `maxPendingInboundDispatch`.

### Changed

- Inbound application dispatch now uses bounded concurrency with bounded pending queue.
- Control messages (`INFO`, `PING`, `PONG`, `ERR`) remain inline even when application dispatch is saturated.
- Pending inbound overflow now triggers a controlled transport failure instead of silent drop.

### Fixed

- Potential runaway growth of async inbound callback tasks after the move to async dispatch.
- Transport behavior under burst inbound traffic is now bounded by scheduler limits.
- Controlled failure path is used when inbound dispatch queue capacity is exceeded.

For full details, see [RELEASE_NOTES_1.0.3.md](RELEASE_NOTES_1.0.3.md).

## [1.0.2] - 2026-03-11

- Enforced handshake readiness barrier before application-level commands.
- Buffered SUB / PUB / HPUB until the NATS connection reaches READY state.
- Added ProtocolParser support for inbound PONG.
- Ensured reconnect path re-applies handshake barrier and replays subscriptions before buffered publish.

### Notes

This fix resolves `Authorization Violation` errors observed on clusters that strictly enforce NATS handshake ordering.

For full details, see [RELEASE_NOTES_1.0.2.md](RELEASE_NOTES_1.0.2.md).

## [1.0.1] - 2026-03-04

### Changed

- Corrected release description metadata after the initial stable rollout.

For full details, see [RELEASE_NOTES_1.0.1.md](RELEASE_NOTES_1.0.1.md).

## [1.0.0] - 2026-03-02

First stable release (`v1.0.0`).
Release: <https://github.com/Dorpmaster/nats/releases/tag/1.0.0>

### Added

- Production-ready Core NATS client
- Reconnect engine with cancellation-aware backoff
- Cluster failover via ServerPool
- TLS (single-node and cluster, optional mTLS)
- JetStream Admin (control plane)
- JetStream Publisher with PubAck
- JetStream Pull consumer (fetch + continuous loop)
- Backpressure (inbound + outbound)
- Outbound write buffer service with overflow policy and drain support
- Ping-based health monitoring (RTT + timeout hooks)
- PSR-3 logging for JetStream
- Metrics hooks via collector interface
- Dockerized Core and JetStream worker examples
- Deterministic integration suites (cluster + TLS + JetStream)

### Changed

- Reconnect stabilization
- Replace sleep/usleep with Amp delay
- Improved example workers
- Finalized stable release metadata for 1.0.0

### Fixed

- Reconnect race windows
- Write buffer edge cases
- TLS failover readiness issues
- Reconnect/failover orchestration edge cases under cluster restarts

For full details, see [RELEASE_NOTES_1.0.0.md](RELEASE_NOTES_1.0.0.md).

## [1.0.0-rc1] - 2026-02-28

### Added

- Typed `ClientState` enum and validated state-transition matrix
- Reconnect orchestration with cancellation-aware backoff strategy
- Server pool with round-robin selection, dead-server cooldown, and discovery from `INFO.connect_urls`
- TLS support for single-node and cluster scenarios (`verifyPeer`, CA chain, SNI, optional mTLS)
- Outbound `WriteBufferService` with bounded queue, overflow policy (`ERROR`/`DROP_NEW`), and drain-aware flushing
- Inbound backpressure limits in `MessageDispatcher` for pending messages and pending bytes
- Ping health monitoring with RTT measurement and timeout handling
- Metrics hooks via `MetricsCollectorInterface` + default no-op collector
- Integration suites for single-node, TLS, cluster failover, and cluster TLS failover

### Changed

- Reconnect/failover policy now marks server dead only after confirmed `open()` failure
- Test architecture standardized to strict AAA style with deterministic bounded waits
- Project packaging set for library usage and release-ready metadata

### Fixed

- Multiple reconnect/backoff race windows with epoch-guarded cancellation flow
- Write-buffer edge cases around failed frame replay and pending counter consistency
- TLS failover readiness and orchestration flakiness in cluster integration tests
