# Changelog

All notable changes to this project are documented in this file.

## [1.0.0-rc1] - 2026-02-28

### Added

- Typed `ClientState` enum and validated state-transition matrix.
- Reconnect orchestration with cancellation-aware backoff strategy.
- Server pool with round-robin selection, dead-server cooldown, and discovery from `INFO.connect_urls`.
- TLS support for single-node and cluster scenarios (verify peer, CA chain, SNI, optional mTLS).
- Outbound `WriteBufferService` with bounded queue, overflow policy (`ERROR`/`DROP_NEW`), and drain-aware flushing.
- Inbound backpressure limits in `MessageDispatcher` for pending messages and pending bytes.
- Ping health monitoring with RTT measurement and timeout handling.
- Metrics hooks via `MetricsCollectorInterface` + default no-op collector.
- Integration suites for single-node, TLS, cluster failover, and cluster TLS failover.

### Changed

- Reconnect/failover policy now marks server dead only after confirmed `open()` failure.
- Test architecture standardized to strict AAA style with deterministic bounded waits.
- Project packaging set for library usage and release-ready metadata.

### Fixed

- Multiple reconnect/backoff race windows with epoch-guarded cancellation flow.
- Write-buffer edge cases around failed frame replay and pending counter consistency.
- TLS failover readiness and orchestration flakiness in cluster integration tests.
