# Architecture Overview

This library separates orchestration concerns from transport and buffering logic.

## Core Components

- `Client`
  - Orchestrates lifecycle state transitions.
  - Coordinates reconnect, drain, ping hooks, and subscription restore.
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
  - Supports flush/drain and failed-frame recovery after reconnect.
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
3. `Client` moves to `CONNECTED`, starts writer + optional ping loop.
4. On transport/parser failure, `Client` enters `RECONNECTING` and runs failover/backoff.
5. On reconnect success, subscriptions are re-sent and state returns to `CONNECTED`.
6. `drain()` enters `DRAINING`, flushes and unsubscribes, then closes to `CLOSED`.

## Design Constraints

- No unbounded retries without explicit config.
- No hidden `sleep()` in test orchestration.
- Services do not mutate client state directly unless explicitly orchestrated by `Client`.
