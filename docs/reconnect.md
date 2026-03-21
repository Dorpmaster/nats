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
