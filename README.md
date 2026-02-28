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

Inbound slow-consumer limits are configured in `MessageDispatcher` (not in `ClientConfiguration`):

```php
$dispatcher = new MessageDispatcher(
    connectInfo: $connectInfo,
    storage: $storage,
    logger: $logger,
    maxPendingMessagesPerSubscription: 1000,
    slowConsumerPolicy: SlowConsumerPolicy::ERROR,
    maxPendingBytesPerSubscription: 2_000_000,
);
```

Details:

- [docs/backpressure.md](docs/backpressure.md)

Outbound write-buffer is configured in `ClientConfiguration`:

```php
$clientConfig = new ClientConfiguration(
    maxWriteBufferMessages: 10000,
    maxWriteBufferBytes: 5_000_000,
    writeBufferPolicy: WriteBufferPolicy::ERROR,
    bufferWhileReconnecting: false,
);
```

Runtime model: `Client` delegates outbound queueing/sending to `WriteBufferService` (queue + writer-loop + drain).

## TLS

```php
$tls = new TlsConfiguration(
    enabled: true,
    verifyPeer: true,
    caFile: __DIR__ . '/certs/ca.pem',
    serverName: 'localhost',
);

$connectionConfig = new ConnectionConfiguration(
    host: 'localhost',
    port: 4223,
    tls: $tls,
);
```

Details:

- [docs/tls.md](docs/tls.md)

## Cluster

Cluster discovery groundwork is available:

- seed servers can be configured in `ClientConfiguration`
- `INFO.connect_urls` can be discovered and added to an internal server pool

Reconnect failover can switch to the next node in server pool and mark failed node as temporarily dead.
Server is marked dead only when `open()` to that server fails.

Example seed list:

```php
$config = new ClientConfiguration(
    reconnectEnabled: true,
    servers: [
        new ServerAddress('n1', 4222),
        new ServerAddress('n2', 4222),
        new ServerAddress('n3', 4222),
    ],
    deadServerCooldownMs: 2000,
);
```

Details:

- [docs/cluster.md](docs/cluster.md)

## Metrics Hooks

```php
$clientConfig = new ClientConfiguration(
    metricsCollector: $collector,
);
```

Details:

- [docs/metrics.md](docs/metrics.md)

## Health / Ping RTT

```php
$clientConfig = new ClientConfiguration(
    pingEnabled: true,
    pingIntervalMs: 30_000,
    pingTimeoutMs: 2_000,
    pingReconnectOnTimeout: true,
);
```

Details:

- [docs/health.md](docs/health.md)

## Testing

- Unit: `make phpunit`
- Integration (real `nats-server`): `make integration`
- Integration TLS: `make integration-tls`
- Full suite: `make test`
