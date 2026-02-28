# NATS AMPHP Client

Asynchronous NATS client for PHP 8.5+ built on AMPHP 3.x.

This library provides a production-oriented Core NATS client with reconnect orchestration, cluster failover, TLS, bounded buffering, and deterministic test coverage for failure scenarios.

Status: **1.0.0-rc1**.

## What This Library Provides

- Async NATS client built on AMPHP
- Automatic reconnect with bounded backoff and jitter
- Cluster discovery via `INFO.connect_urls`
- Cluster failover with round-robin server pool and dead-server cooldown
- TLS support (`verifyPeer`, `caFile`/`caPath`, SNI, optional mTLS)
- Inbound backpressure (`ERROR` / `DROP_NEW`) in `MessageDispatcher`
- Outbound write buffer with bounded queue and overflow policy
- Ping-based health monitoring (RTT + timeout hooks)
- Drain lifecycle (`DRAINING` -> `CLOSED`) for graceful shutdown
- Deterministic unit + integration tests (single server, TLS, cluster, cluster+TLS)

## Installation

```bash
composer require dorpmaster/nats
```

Requirements:

- PHP `^8.5`
- `ext-event`
- `ext-pcntl`
- OpenSSL support for TLS connections

## Quick Start (Single Server)

```php
<?php

declare(strict_types=1);

use Amp\SignalCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;

$cancellation = new SignalCancellation([SIGINT, SIGTERM, SIGHUP]);
$connector = new RetrySocketConnector(new DnsSocketConnector());

$connection = new Connection(
    connector: $connector,
    configuration: new ConnectionConfiguration('127.0.0.1', 4222),
);

$storage = new SubscriptionStorage();
$dispatcher = new MessageDispatcher(
    connectInfo: new ConnectInfo(false, false, false, 'php', PHP_VERSION),
    storage: $storage,
);

$client = new Client(
    configuration: new ClientConfiguration(),
    cancellation: $cancellation,
    connection: $connection,
    eventDispatcher: new EventDispatcher(),
    messageDispatcher: $dispatcher,
    storage: $storage,
);

$client->connect();

$sid = $client->subscribe('demo.hello', static function ($message): null {
    echo "received: {$message->getPayload()}\n";

    return null;
});

$client->publish(new PubMessage('demo.hello', 'hello'));
$client->unsubscribe($sid);
$client->disconnect();
```

## Cluster Example

```php
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;

$config = new ClientConfiguration(
    reconnectEnabled: true,
    servers: [
        new ServerAddress('n1', 4222),
        new ServerAddress('n2', 4222),
        new ServerAddress('n3', 4222),
    ],
    deadServerCooldownMs: 2_000,
    maxReconnectAttempts: 20,
    reconnectBackoffInitialMs: 50,
    reconnectBackoffMaxMs: 1_000,
);
```

Failover model in v1:

- reconnect tries current server first;
- a server is marked dead only after confirmed `open()` failure;
- next attempts use round-robin over known servers (seed + discovered);
- dead servers are skipped until cooldown expires.

See [docs/cluster.md](docs/cluster.md).

## TLS Example

```php
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;

$tls = new TlsConfiguration(
    enabled: true,
    verifyPeer: true,
    caFile: __DIR__ . '/certs/ca.pem',
    clientCertFile: __DIR__ . '/certs/client.pem',
    clientKeyFile: __DIR__ . '/certs/client-key.pem',
    serverName: 'nats.internal',
);

$connectionConfig = new ConnectionConfiguration(
    host: 'nats.internal',
    port: 4223,
    tls: $tls,
);
```

See [docs/tls.md](docs/tls.md).

## Cluster + TLS Example

```php
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;

$connectionConfig = new ConnectionConfiguration(
    host: 'n1',
    port: 4222,
    tls: new TlsConfiguration(
        enabled: true,
        verifyPeer: true,
        caFile: __DIR__ . '/certs/ca.pem',
    ),
);

$clientConfig = new ClientConfiguration(
    reconnectEnabled: true,
    servers: [
        new ServerAddress('n1', 4222, true),
        new ServerAddress('n2', 4222, true),
        new ServerAddress('n3', 4222, true),
    ],
);
```

See [docs/cluster-tls.md](docs/cluster-tls.md).

## Configuration Reference

### Reconnect

| Option | Default | Description |
| --- | --- | --- |
| `reconnectEnabled` | `false` | Enables reconnect flow |
| `maxReconnectAttempts` | `10` (`null` = infinite) | Max reconnect attempts |
| `reconnectBackoffInitialMs` | `50` | Initial backoff delay |
| `reconnectBackoffMaxMs` | `1000` | Max backoff delay |
| `reconnectBackoffMultiplier` | `2.0` | Exponential multiplier |
| `reconnectJitterFraction` | `0.2` | Additive jitter fraction |
| `deadServerCooldownMs` | `2000` | Time to skip dead server |
| `servers` | `[]` | Seed servers for pool/failover |

### Backpressure (Inbound)

Configured in `MessageDispatcher` constructor:

| Option | Default | Description |
| --- | --- | --- |
| `maxPendingMessagesPerSubscription` | `1000` | Pending message limit per subscription |
| `maxPendingBytesPerSubscription` | `2000000` | Pending bytes limit per subscription (`null` disables) |
| `slowConsumerPolicy` | `ERROR` | `ERROR` or `DROP_NEW` |

### Write Buffer (Outbound)

Configured in `ClientConfiguration`:

| Option | Default | Description |
| --- | --- | --- |
| `maxWriteBufferMessages` | `10000` | Max queued outbound frames |
| `maxWriteBufferBytes` | `5000000` | Max queued outbound bytes |
| `writeBufferPolicy` | `ERROR` | `ERROR` or `DROP_NEW` |
| `bufferWhileReconnecting` | `false` | Allow enqueue in `RECONNECTING/CONNECTING` |

### Ping / Health

| Option | Default | Description |
| --- | --- | --- |
| `pingEnabled` | `false` | Enable ping loop |
| `pingIntervalMs` | `30000` | Ping interval |
| `pingTimeoutMs` | `2000` | Timeout waiting for PONG |
| `pingReconnectOnTimeout` | `true` | Trigger reconnect on timeout |

### TLS

| Option | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Enable TLS |
| `verifyPeer` | `true` | Verify certificate and host |
| `caFile` / `caPath` | `null` | CA bundle location |
| `clientCertFile` / `clientKeyFile` | `null` | Client certificate/key for mTLS |
| `serverName` | `null` | SNI / peer-name override |
| `allowSelfSigned` | `false` | Relaxed local setup |
| `minVersion` | `null` | Minimum TLS version |
| `alpnProtocols` | `null` | ALPN protocol list |

## Production Considerations

- `publish()` is at-least-once under reconnect windows; duplicate delivery is possible.
- `request()` is not silently retried; timeout/failure can happen during disconnect.
- Inbound overflow behavior depends on configured policy (`ERROR` fails fast, `DROP_NEW` drops new messages).
- Outbound overflow behavior depends on `writeBufferPolicy` (`ERROR` throws `WriteBufferOverflowException`, `DROP_NEW` drops new frame).
- Memory usage is bounded only if your configured limits are bounded.
- `drain()` is graceful but bounded by timeout and network state.

## Testing

```bash
make phpunit
make integration
make integration-tls
make integration-cluster
make integration-cluster-tls
make test
```

## Stability & Versioning

- Versioning follows SemVer.
- Public API includes classes and interfaces under `src/Domain/*` and documented entry points (`Client`, `ConnectionConfiguration`, `ClientConfiguration`, protocol message contracts).
- Internal behavior may evolve between RC builds if API compatibility is preserved.

## Documentation Index

- [docs/architecture.md](docs/architecture.md)
- [docs/state-machine.md](docs/state-machine.md)
- [docs/reconnect.md](docs/reconnect.md)
- [docs/cluster.md](docs/cluster.md)
- [docs/cluster-tls.md](docs/cluster-tls.md)
- [docs/backpressure.md](docs/backpressure.md)
- [docs/write-buffer.md](docs/write-buffer.md)
- [docs/metrics.md](docs/metrics.md)
- [docs/health.md](docs/health.md)
- [docs/drain.md](docs/drain.md)
- [docs/tls.md](docs/tls.md)

## License

MIT. See [LICENSE](LICENSE).
