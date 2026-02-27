# NATS AMPHP Client

## Reliability / Reconnect

Reconnect v1 is available via `ClientConfiguration`:

```php
$config = new ClientConfiguration(
    reconnectEnabled: true,
    maxReconnectAttempts: 20,
    reconnectBackoffInitialMs: 50,
    reconnectBackoffMaxMs: 500,
    reconnectBackoffMultiplier: 2.0,
    reconnectJitterFraction: 0.2,
);
```

See detailed semantics:

- [docs/reconnect.md](docs/reconnect.md)
- [docs/state-machine.md](docs/state-machine.md)

## Run Tests

- Unit: `make phpunit`
- Integration (real nats-server): `make integration`
- Full suite: `make test`
