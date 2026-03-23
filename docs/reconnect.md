# Reconnect

Reconnect is configured via `ClientConfiguration`.

```php
$config = new ClientConfiguration(
    reconnectEnabled: true,
    maxReconnectAttempts: 20, // null => infinite
    reconnectBackoffInitialMs: 50,
    reconnectBackoffMaxMs: 1000,
    reconnectBackoffMultiplier: 2.0,
    reconnectJitterFraction: 0.2,
    deadServerCooldownMs: 2000,
);
```

Seed servers for reconnect / failover are configured through `servers`:

```php
$config = new ClientConfiguration(
    reconnectEnabled: true,
    servers: [
        new ServerAddress('n1', 4222),
        new ServerAddress('n2', 4222),
    ],
);
```

The legacy `reconnectServers` configuration field is no longer part of the public API.


## Backoff Formula

For attempt `n`:

- `base = min(max, initial * multiplier^(n-1))`
- `delay = base + random(0..base*jitterFraction)`

Delay waiting is delegated to `DelayStrategyInterface`.

## Cancellation Semantics

- Backoff wait accepts `Cancellation`.
- `close()` / `drain()` cancel active reconnect delay immediately.
- `CancelledException` in backoff is lifecycle control flow, not a reconnect failure by itself.

## Epoch Guard

Reconnect wakeups are protected by epoch:

- entering `RECONNECTING` increments reconnect epoch;
- each wait captures epoch;
- stale wakeup (`epoch` changed or state is no longer `RECONNECTING`) exits with no side effects.

`connectionStatusChanged` for `RECONNECTING` is emitted only after this backoff
context is initialized, so listeners do not observe `RECONNECTING` with stale
or missing reconnect lifecycle internals.

## Dead Server Policy

- reconnect tries current server first;
- server is marked dead only if `open()` to that server fails;
- dead server is skipped until cooldown expires;
- next candidate is selected from `ServerPoolService` round-robin set.

## Request/Publish Semantics During Reconnect

- `publish()/request()` fail-fast by default (`bufferWhileReconnecting=false`).
- with `bufferWhileReconnecting=true`, frames are queued in outbound write buffer (bounded by configured limits).
- after reconnect `open()`, application frames still wait behind the initial protocol barrier: `INFO -> CONNECT -> PING/PONG -> READY`.
- existing subscriptions are replayed immediately after reconnect readiness and before buffered application publishes are flushed.
- `request()` is not silently retried.
- request setup (`SUB` reply inbox + request `PUB/HPUB`) follows the same buffering rules and will not hit the socket before readiness.

## Inbound Dispatch During Reconnect

- inbound `MSG/HMSG` callbacks continue to run in their own async tasks if they were already scheduled before the transport failure;
- the scheduler queue is reset on reconnect so stale pending application dispatch does not block new post-reconnect traffic;
- after reconnect, newly parsed inbound messages are scheduled with the same bounded async dispatch path;
- callback exceptions remain isolated and do not by themselves terminate reconnect processing;
- lifecycle shutdown (`drain()/disconnect()`) stops scheduling new inbound callbacks and waits bounded time for active + pending dispatch drain before closing.

## State-Change Event Semantics

State-change events are post-effects events.

- `RECONNECTING` is dispatched after reconnect token/backoff state and write-path mode are already updated.
- `CONNECTED` is dispatched after the protocol readiness barrier completes, not immediately after low-level socket open.
- `CLOSED` is dispatched after lifecycle services are stopped for the closed state.

## Reconnect Step-by-Step Timeline

This is the effective reconnect timeline observed by the current client:

1. Failure is detected from read loop, parser path, ping timeout, or write buffer failure handler.
2. Client commits `RECONNECTING`.
3. Internal reconnect effects run before any state-change event:
   - ping stops
   - reconnect backoff epoch/cancellation context is created
   - inbound scheduler stops accepting new application callbacks
   - write buffer switches to reconnect mode
4. `connectionStatusChanged(RECONNECTING)` is dispatched.
5. Reconnect attempts run with current-server-first policy and epoch-guarded backoff.
6. When `open()` succeeds, client commits `CONNECTED`.
7. Internal connected effects run:
   - scheduler reset
   - handshake flags reset
   - write buffer starts in paused mode
8. Reader loop re-enters protocol readiness barrier:
   - receive `INFO`
   - send `CONNECT`
   - send `PING`
   - receive `PONG`
9. `completeHandshake()` resumes write buffer, replays subscriptions if needed, and starts ping service.
10. Only then is `connectionStatusChanged(CONNECTED)` dispatched.

This is why `CONNECTED` event listeners can safely treat the client as
post-ready rather than merely post-open.
