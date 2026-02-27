# Drain v1

## Purpose

`drain()` performs graceful shutdown:

1. transition to `DRAINING`,
2. finish current in-flight dispatch (bounded by timeout),
3. unsubscribe active subscriptions,
4. close transport and transition to `CLOSED`.

`disconnect()` delegates to `drain()`.

## API

```php
$client->drain();          // default timeout
$client->drain(5000);      // 5s timeout in milliseconds
```

## Semantics

- `publish()` and `request()` during `DRAINING` / `CLOSED` fail predictably.
- `drain()` is idempotent.
- Reconnect is not started during `DRAINING`.
