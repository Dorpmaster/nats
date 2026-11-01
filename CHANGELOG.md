# Changelog

All notable changes to this project are documented in this file.

## [1.3.0] - Unreleased

### Added

- Add optional JetStream server capability contracts based on current-transport INFO `api_lvl`, configurable API prefixes, server `err_code` access, nullable atomic/batched publishing and consumer-limit stream options, and selected-field stream UPDATE with exact JSON configuration preservation and one operation deadline. Existing interfaces, constructors and dependency floors remain compatible.

### Fixed

- Stream UPDATE requests use a cancellable single-attempt queue lifetime: uncertain writes are never replayed after reconnect, and expired or cancelled unsent frames are withdrawn. Ordinary publication replay is unchanged.

## [1.2.1] - Unreleased

### Changed

- Raised the minimum `amphp/byte-stream` version to `2.1.3` (`^2.1.3`, allowing `>=2.1.3 <3.0.0`) to prevent stalled TLS reads with non-select event-loop drivers and report an error when writing to a disconnected peer makes `fwrite()` return zero bytes. See the [AMPHP Byte Stream 2.1.3 release notes](https://github.com/amphp/byte-stream/releases/tag/v2.1.3).

### Fixed

- Apply `allowSelfSigned` to PHP's `allow_self_signed` stream option without lowering TLS security levels. Keep peer/name verification, CA, minimum TLS, ALPN and mTLS settings intact across reconnect/failover, reject unsupported sockets explicitly, and close/detach failed transports before retry.

- Process asynchronous INFO as server/topology updates without repeating CONNECT or handshake PING. Client owns handshake state per transport, preserving readiness, subscription replay order, ping monitoring and safe logs across updates and handshake-write failures.

- Read JetStream delivery counts from validated classic or domain/account ACK subjects for MSG and HMSG, independently of opaque extension tokens including numeric tails. User `Nats-Num-Delivered` headers no longer influence automatic DROP_NEW termination; malformed or overflowing metadata yields an unknown count without interrupting message delivery.

- Preserve ordinary HMSG headers containing `NATS/`, URLs and multiple colons, including repeated values in their original order. Recognize only the first, valid `NATS/1.0` preamble as protocol metadata while retaining separate statuses and received body sizes.

- Wait for asynchronous JetStream `fetch(noWait: true)` replies and server completion instead of removing the inbox immediately. Keep a separate local timeout, preserve HMSG status codes/descriptions (including status-only frames), handle heartbeat and byte-limit completion, and clean up each request independently on success or failure.

- Keep Core and JetStream HPUB lengths consistent with mutable header bags, and send/retry the exact serialized frame captured at enqueue time so wire bytes and queue byte accounting remain aligned.
- Prevent connection credentials from reaching protocol logs before PSR-3 formatting. In previous versions, enabling DEBUG with the standard formatter could write connection credentials from CONNECT messages; handshake, reconnect, socket I/O, and error logs now use safe metadata without raw frames or payloads.
- Preserve valid JetStream resource names such as `ORDERS@EU`, `ORDERS+EU`, and `WORKER@EU` by separating command-token safety checks from the existing Core publish subject rules.
- Reject invalid JetStream stream and durable consumer names before API requests or pull inbox subscriptions. Validate outgoing PUB/HPUB subjects and reply-to fields, including direct transport frames, and reject trailing newlines in SUB/UNSUB command fields to prevent NATS command injection. Binary payloads and existing subscription patterns remain supported.

### Notes

- Applications locking `amphp/byte-stream` to `2.1.2` or earlier must update it together with the driver when upgrading to `1.2.1`.

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
