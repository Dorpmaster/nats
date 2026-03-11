# Architecture Overview

This library separates orchestration concerns from transport and buffering logic.

## Core Components

- `Client`
  - Orchestrates lifecycle state transitions.
  - Coordinates reconnect, drain, ping hooks, initial handshake barrier, and subscription restore.
  - Routes application inbound messages into a bounded async dispatch scheduler.
  - Delegates outbound buffering to `WriteBufferService`.
- `InboundDispatchScheduler`
  - Owns bounded concurrency for inbound `MSG/HMSG` callback execution.
  - Owns bounded pending queue, overflow detection, and bounded drain waiting.
  - Keeps application dispatch saturation isolated from control-message processing.
- `Connection`
  - Owns socket lifecycle and protocol parser feed.
  - Produces protocol messages from inbound stream.
  - Supports server selection (`ServerAddress`) and TLS context.
- `ServerPoolService`
  - Tracks seed + discovered servers.
  - Provides round-robin selection with dead-server cooldown.
- `WriteBufferService`
  - Owns outbound queue, pending counters, overflow policy.
  - Runs writer loop against active connection.
  - Supports pause/resume for handshake gating, flush/drain, and failed-frame recovery after reconnect.
- `PingService`
  - Sends periodic PING, tracks PONG RTT, detects timeout.
  - Emits telemetry and callback signals.
- `ReconnectBackoffService`
  - Calculates and awaits reconnect delays using delay strategy.

## Component Interaction

```text
+-----------------------+         +--------------------------+
|        Client         |<------->|      ServerPoolService   |
| state + orchestration |         | rr + cooldown + discovery|
+-----------+-----------+         +--------------------------+
            |
            | delegates outbound
            v
+-----------------------+         +--------------------------+
|   WriteBufferService  |-------->|        Connection        |
| queue + writer-loop   | writes  | socket + parser + TLS    |
+-----------+-----------+         +--------------------------+
            |
            | health callbacks / metrics
            v
+-----------------------+         +--------------------------+
|      PingService      |         | ReconnectBackoffService  |
| ping/pong + timeout   |         | backoff + cancellation   |
+-----------------------+         +--------------------------+
```

## Lifecycle Summary

1. `connect()` moves `NEW|CLOSED -> CONNECTING`.
2. `Connection::open()` establishes socket and parser feed.
3. `Client` moves to `CONNECTED`, but outbound application writes stay paused until initial protocol readiness completes.
4. After inbound `INFO`, client sends `CONNECT`, then `PING`, then waits for inbound `PONG` before resuming buffered application writes and starting regular ping health monitoring.
5. Inbound `MSG/HMSG` handlers are scheduled asynchronously after parsing, subject to bounded concurrency and bounded pending queue limits.
6. On transport/parser failure, `Client` enters `RECONNECTING` and runs failover/backoff.
7. On reconnect success, the same readiness barrier is applied again; restored subscriptions are replayed before buffered application frames are flushed.
8. `drain()` enters `DRAINING`, stops accepting new application inbound callbacks, waits bounded time for active + pending dispatch drain, then flushes and unsubscribes before closing to `CLOSED`.

## Design Constraints

- No unbounded retries without explicit config.
- No hidden `sleep()` in test orchestration.
- Services do not mutate client state directly unless explicitly orchestrated by `Client`.

## Inbound Dispatch Execution Model

- inbound protocol parsing stays on the connection reader loop;
- control messages (`INFO`, `PING`, `PONG`, `ERR`) stay inline on the reader loop;
- application callbacks for `MSG/HMSG` are not executed inline on that loop;
- application dispatch is bounded by `maxInboundDispatchConcurrency`;
- pending application dispatch is bounded by `maxPendingInboundDispatch`;
- callback completion order is not guaranteed, even though message parse order is preserved;
- callback failures are isolated from the reader loop and are logged instead of terminating transport processing;
- pending queue overflow is a controlled transport failure and never silently drops inbound messages.

Handshake ordering is protected by a protocol conformance test using a fake NATS server. The client guarantees that application-level commands are buffered until the connection reaches READY (`INFO -> CONNECT -> PING/PONG`).
