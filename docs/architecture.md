# Architecture Overview

This library separates orchestration concerns from transport and buffering logic.

## Core Components

- `Client`
  - Orchestrates lifecycle state transitions.
  - Coordinates reconnect, drain, ping hooks, initial handshake barrier, and subscription restore.
  - Schedules inbound subscription callbacks outside the socket read / parse path.
  - Delegates outbound buffering to `WriteBufferService`.
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
5. Inbound `MSG/HMSG` handlers are scheduled asynchronously after parsing, so the reader loop can continue receiving subsequent frames while earlier callbacks are still running.
6. On transport/parser failure, `Client` enters `RECONNECTING` and runs failover/backoff.
7. On reconnect success, the same readiness barrier is applied again; restored subscriptions are replayed before buffered application frames are flushed.
8. `drain()` enters `DRAINING`, stops scheduling new inbound callbacks, waits bounded time for active callback tasks, then flushes and unsubscribes before closing to `CLOSED`.

## Design Constraints

- No unbounded retries without explicit config.
- No hidden `sleep()` in test orchestration.
- Services do not mutate client state directly unless explicitly orchestrated by `Client`.

## Inbound Dispatch Execution Model

- inbound protocol parsing stays on the connection reader loop;
- subscription callbacks are not executed inline on that loop;
- each inbound dispatch is scheduled asynchronously by `Client`;
- callback completion order is not guaranteed, even though message parse order is preserved;
- callback failures are isolated from the reader loop and are logged instead of terminating transport processing;
- `Client` tracks active inbound dispatch tasks so `drain()` can wait for them without introducing public API.

Handshake ordering is protected by a protocol conformance test using a fake NATS server. The client guarantees that application-level commands are buffered until the connection reaches READY (`INFO -> CONNECT -> PING/PONG`).
