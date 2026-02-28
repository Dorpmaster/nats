# Drain

`drain()` provides graceful shutdown semantics.

## Sequence

1. State transitions to `DRAINING`.
2. New publish/request operations are rejected.
3. In-flight dispatch is allowed to finish (bounded by timeout).
4. Active subscriptions are unsubscribed.
5. Outbound write buffer is flushed.
6. Connection is closed and state becomes `CLOSED`.

## API

```php
$client->drain();
$client->drain(5_000); // timeout in ms
```

## Guarantees and Limits

- `drain()` is idempotent.
- Reconnect is not started while draining.
- Drain is best-effort under configured timeout and network conditions.
