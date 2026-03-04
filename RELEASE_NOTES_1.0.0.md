# Release Notes — 1.0.0

## Overview

Version `1.0.0` is the first stable release of the project.
It delivers a production-oriented async NATS client for PHP 8.5+ on AMPHP 3.x, including reconnect/failover, TLS, bounded buffering, deterministic tests, and JetStream MVP support.

This release finalizes the API and behavior introduced during the pre-stable cycle and marks the project as stable in release metadata and documentation.

## Core NATS Features

- Full async Core NATS client on AMPHP.
- Explicit client state machine with validated transitions.
- Reconnect orchestration with cancellation-aware exponential backoff and jitter.
- Cluster discovery via `INFO.connect_urls`.
- Cluster failover through `ServerPool` (round-robin + dead-server cooldown).
- TLS support for single-node and cluster environments:
  - `verifyPeer`
  - CA chain (`caFile` / `caPath`)
  - SNI/server-name override
  - optional mTLS client certificate/key
- Inbound backpressure controls (`MessageDispatcher`):
  - pending messages limit
  - pending bytes limit
  - policies: `ERROR`, `DROP_NEW`
- Outbound write buffer with limits and overflow policy.
- Drain lifecycle for graceful stop.
- Ping health monitoring (RTT + timeout-triggered reconnect path).

## JetStream Support

JetStream support in `1.0.0` includes MVP-level admin and data-plane workflows:

- JetStream Admin (control plane):
  - create/get/delete stream
  - create/update/get/delete consumer metadata
- JetStream Publisher:
  - publish with PubAck parsing
  - msg-id dedup support
  - expectation headers support
- JetStream Pull Consumer:
  - bounded `fetch()`
  - continuous `consume()` loop
  - explicit ack API (`ack`, `nak`, `term`, `inProgress`)
  - local in-flight backpressure
  - drain timeout semantics
  - reconnect-aware continuous loop behavior

## Observability

- Metrics hooks via collector interface (no vendor lock-in).
- PSR-3 logging across JetStream service layer:
  - control-plane request/response/error
  - publish lifecycle events
  - pull fetch/consume loop events
  - ack operation events
- Logging is implemented in services/transport/loops; DTO/model objects remain clean.

## Docker & Examples

- Root Dockerfile runs on `php:8.5-zts-trixie`.
- Container defaults to Core worker (`bin/worker.php`).
- JetStream worker example available (`bin/jetstream_worker.php`) via command override.
- README includes Docker build/run instructions and environment variables for both workers.

## Test Coverage

The project includes deterministic test suites with bounded waits (no arbitrary `sleep/usleep`):

- Unit tests
- Core integration tests (single-node)
- TLS integration tests
- Cluster failover integration tests
- Cluster TLS failover integration tests
- JetStream integration tests (single-node)
- JetStream cluster reconnect/failover integration tests
- JetStream cluster TLS integration tests

## Stability Guarantees

- Stable release line starts at `1.0.0`.
- Versioning follows SemVer.
- Public API is documented via `src/Domain/*` interfaces and README/docs entry points.
- Behavior is validated with deterministic unit/integration coverage.

## Behavioral Guarantees

- Core publish/subscribe behavior is async and bounded by configured limits.
- Reconnect is cancellation-aware and follows configured attempts/backoff.
- Failover marks servers dead only after confirmed open/connect failure.
- JetStream semantics remain at-least-once; duplicates are possible in reconnect windows.
- `request()` is not silently retried as exactly-once behavior cannot be guaranteed.
- `drain()` is graceful but bounded by timeout and in-flight completion.

## Upgrade Notes

- Upgrade target is stable `1.0.x` line.
- Replace any pre-stable version pinning with `^1.0`.
- Review reconnect, write-buffer, and backpressure defaults in your deployment config.
- For cluster+TLS setups, verify SAN/SNI alignment on all failover targets.
- For JetStream publishing, continue using msg-id for dedup where required.

## Roadmap

Near-term roadmap after `1.0.0`:

- incremental JetStream capability expansion beyond MVP
- additional observability hooks and diagnostics
- operational hardening based on production feedback

## Tag/Status

- Version: `1.0.0`
- Status: `stable`
- Release date: `2026-02-28`
