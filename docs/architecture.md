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
4. After the first `INFO` on a new transport, client sends `CONNECT`, then `PING`, then waits for inbound `PONG` before resuming buffered application writes and starting regular ping health monitoring.
5. Inbound `MSG/HMSG` handlers are scheduled asynchronously after parsing, subject to bounded concurrency and bounded pending queue limits.
6. On transport/parser failure, `Client` enters `RECONNECTING` and runs failover/backoff.
7. On reconnect success, the same readiness barrier is applied again; restored subscriptions are replayed before buffered application frames are flushed.
8. `drain()` enters `DRAINING`, stops accepting new application inbound callbacks, waits bounded time for active + pending dispatch drain, then flushes and unsubscribes before closing to `CLOSED`.

## Asynchronous INFO and the transport handshake

`Client` owns the handshake attempt for each successfully opened transport. It
marks the attempt before writing CONNECT, so another INFO cannot initiate a
second CONNECT/PING while the first handshake is waiting for PONG. Every INFO
still reaches the dispatcher to update supported server metadata and the existing
`connect_urls` discovery path. Missing URLs do not clear the server pool.

The dispatcher interface remains unchanged: third-party dispatchers may return a
CONNECT proposal for every INFO; Client sends only the first proposal per transport.
Later INFO neither replays subscriptions nor resumes/reorders the queue, restarts
ping monitoring or repeats CONNECTED. Server PING/PONG and periodic client PING
continue independently. Only the initial handshake PONG makes the transport READY.

A new transport after reconnect or explicit disconnect/connect resets this state.
CONNECT or handshake-PING write failure enters the normal reconnect/close path;
the next transport gets a fresh handshake attempt. Diagnostics retain only safe
scalar metadata, without CONNECT objects or credentials.

## Transition Orchestration

State transitions are applied in three ordered stages:

1. validate + state commit
2. internal transition effects
3. state-change event dispatch

`Client::transitionTo()` is only the state-machine commit step. Lifecycle
orchestration such as reconnect token updates, ping start/stop, write-buffer
mode changes, and inbound scheduler lifecycle is applied after commit and before
the state-change event is emitted.

`connectionStatusChanged` is therefore a post-effects event: listeners should
observe a lifecycle-consistent client for the new state, not a partially
transitioned one.

## Lifecycle Timelines

### `connect()`

```text
connect()
  -> state = CONNECTING
  -> open transport
  -> state = CONNECTED
  -> apply connected effects (scheduler reset, write buffer start+pause)
  -> receive INFO
  -> send CONNECT
  -> send PING
  -> receive PONG
  -> completeHandshake()
  -> resume write buffer / start ping
  -> dispatch connectionStatusChanged(CONNECTED)
```

### reconnect

```text
failure detected
  -> state = RECONNECTING
  -> apply reconnect effects (stop ping, init reconnect epoch, stop scheduler, switch write buffer mode)
  -> dispatch connectionStatusChanged(RECONNECTING)
  -> reconnect attempts + backoff
  -> open succeeds
  -> state = CONNECTED
  -> apply connected effects (scheduler reset, write buffer start+pause)
  -> receive INFO
  -> send CONNECT
  -> send PING
  -> receive PONG
  -> completeHandshake()
  -> replay subscriptions if reconnect
  -> resume write buffer / start ping
  -> dispatch connectionStatusChanged(CONNECTED)
```

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

## Protocol Logging

`Client`, `MessageDispatcher`, and `Connection` emit selected scalar metadata
before calling a PSR-3 logger: command type, handshake stage, client state, byte
counts, and exception type/code. CONNECT objects, connection credentials, socket
chunks, application payloads, and original exception messages/traces are omitted.
The same policy applies after reconnect and to callback/write-buffer failures.

DEBUG remains available with the worker's Monolog, `PsrLogMessageProcessor`, and
AMPHP `ConsoleFormatter` setup. Credential transmission and protocol frames are
unchanged. Application code still receives the original exceptions and causes.

## JetStream server information

Client exposes selected INFO facts through the optional
`ServerInformationProviderInterface` without changing `ClientInterface` or the
dispatcher contract. INFO revision and transport generation invalidate cached
snapshots on an update or lifecycle change. JetStream capabilities are advisory,
per-feature protocol rules; they do not probe/mutate resources or infer account
permissions. Domain/import API targets remain unknown from local INFO alone.
See [JetStream compatibility](jetstream-compatibility.md).

## Mutation requests and replay

Client owns the request future, inbox and timeout; `RequestLifetime` connects that
owner to cancellable queue snapshots. Transport selects single-attempt semantics
for JetStream UPDATE. WriteBuffer owns physical FIFO removal and accounting and
never puts an attempted single-attempt frame into `failedFrame`. A write failure
completes the request with its original cause before invoking normal reconnect
handling. Legacy publication frames retain their replay semantics. The old public
interfaces remain usable; unsupported optional single-attempt capabilities fail
explicitly instead of silently downgrading mutation safety.

## Streaming publication channels

The optional `ConnectionBoundPublishClientInterface` owns a persistent inbox and
per-frame single-attempt write receipts. The JetStream fast session owns flow
credit, cumulative confirmations, its deadline and immutable terminal result.
WriteBuffer consumes the attempt immediately before socket send and reports write
completion after releasing frame accounting. No fast frame enters reconnect
replay; legacy interfaces retain their behavior. See
[streaming publication](jetstream-fast-batch.md).
