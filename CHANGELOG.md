# Changelog

All notable changes to this project are documented in this file.

## [1.0.0] - 2026-02-28

### Added

- Production-ready Core NATS client
- Reconnect engine with cancellation-aware backoff
- Cluster failover via ServerPool
- TLS (single-node and cluster, optional mTLS)
- JetStream Admin (control plane)
- JetStream Publisher with PubAck
- JetStream Pull consumer (fetch + continuous loop)
- Backpressure (inbound + outbound)
- PSR-3 logging for JetStream
- Deterministic integration suites (cluster + TLS + JetStream)

### Changed

- Reconnect stabilization
- Replace sleep/usleep with Amp delay
- Improved example workers

### Fixed

- Reconnect race windows
- Write buffer edge cases
- TLS failover readiness issues
