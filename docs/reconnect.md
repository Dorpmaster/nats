# Reconnect v1

## Overview

Reconnect v1 is opt-in and enabled via `ClientConfiguration`.

```php
$config = new ClientConfiguration(
    reconnectEnabled: true,
    maxReconnectAttempts: 20,          // null => infinite
    reconnectBackoffInitialMs: 50,
    reconnectBackoffMaxMs: 500,
    reconnectBackoffMultiplier: 2.0,
    reconnectJitterFraction: 0.2,      // set 0.0 for deterministic tests
    reconnectServers: ['nats://nats:4222'], // reserved for future multi-server routing
);
```

## Disconnect Triggers

Reconnect is triggered when the active connection drops due to:

- socket EOF (`receive()` returns `null` and connection is closed),
- read error (`receive()` throws),
- parser fatal error surfaced through `receive()` (malformed/unknown frame).

`CancelledException` from receive path is treated as termination signal and stops receive-loop without reconnect.

## Semantics

- Reconnect retries are bounded by `maxReconnectAttempts` unless `null` (infinite).
- Backoff is exponential: `initial * multiplier^(attempt-1)`, capped by `max`.
- Jitter adds `0..(base * jitterFraction)` to each delay.
- Reconnect delays are delegated to `DelayStrategyInterface`:
  - default: `EventLoopDelayStrategy`
  - tests: `ImmediateDelayStrategy` / `RecordingDelayStrategy`
  - strategy receives optional `Cancellation`; `close()` / `drain()` cancel active backoff immediately
- Reconnect does not create additional receive loops: one loop remains active.
- Existing subscriptions are restored automatically after reconnect handshake (after `INFO`).

## Publish / Request Behavior

- `publish()` during disconnection follows current connection contract (send is no-op on closed socket).
- `request()` is **not silently retried**. During disconnect it ends by timeout/cancellation according to provided timeout.

## Limitations of v1

- `reconnectServers` are configuration-ready, but routing/rotation is not implemented yet (single configured server is used).
- No durable buffering/queueing of publish while disconnected.
- No JetStream features in reconnect flow.
