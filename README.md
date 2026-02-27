# NATS AMPHP Client

## Installation

```bash
composer require dorpmaster/nats
```

## Reconnect

Reconnect v1 is configurable via `ClientConfiguration`:

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

Details:

- [docs/reconnect.md](docs/reconnect.md)
- [docs/state-machine.md](docs/state-machine.md)

## Drain

Use graceful shutdown:

```php
$client->drain();
```

Details:

- [docs/drain.md](docs/drain.md)

## Backpressure

Slow-consumer handling is documented here:

- [docs/backpressure.md](docs/backpressure.md)

## Testing

- Unit: `make phpunit`
- Integration (real `nats-server`): `make integration`
- Full suite: `make test`
