# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

## [1.0.1] - 2026-XX-XX

### Fixed

- Enforced handshake readiness barrier before application-level commands.
- Buffered SUB / PUB / HPUB until the NATS connection reaches READY state.
- Added ProtocolParser support for inbound PONG.
- Ensured reconnect path re-applies handshake barrier and replays subscriptions before buffered publish.

### Notes

This fix resolves `Authorization Violation` errors observed on clusters that strictly enforce NATS handshake ordering.

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
