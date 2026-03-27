# Changelog

All notable changes to this project are documented in this file.

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
