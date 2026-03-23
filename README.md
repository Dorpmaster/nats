# NATS PHP Client
Stable: 1.1.1

Asynchronous NATS client for PHP 8.5+ built on AMPHP 3.x.

This library provides a production-oriented Core NATS client with reconnect orchestration, cluster failover, TLS, bounded buffering, and deterministic test coverage for failure scenarios.

Status: **stable** (`v1.1.1`, patch release line).  
Release details: [GitHub Release 1.1.1](https://github.com/Dorpmaster/nats/releases/tag/1.1.1) | [Release Notes](RELEASE_NOTES_1.1.1.md)

Recent transport execution model improvements:

- async inbound dispatch
- bounded inbound dispatch scheduler
- improved transport stability under load
- simplified reconnect seed-server configuration around `servers`
- post-effects lifecycle event dispatch with post-handshake `CONNECTED` semantics

## What This Library Provides

- Async NATS client built on AMPHP
- Automatic reconnect with bounded backoff and jitter
- Cluster discovery via `INFO.connect_urls`
- Cluster failover with round-robin server pool and dead-server cooldown
- TLS support (`verifyPeer`, `caFile`/`caPath`, SNI, optional mTLS)
- Inbound backpressure (`ERROR` / `DROP_NEW`) in `MessageDispatcher`
- Outbound write buffer with bounded queue and overflow policy
- Initial handshake barrier: application frames are released only after `INFO -> CONNECT -> PING/PONG -> READY`
- Ping-based health monitoring (RTT + timeout hooks)
- Drain lifecycle (`DRAINING` -> `CLOSED`) for graceful shutdown
- Deterministic unit + integration tests (single server, TLS, cluster, cluster+TLS)

## Installation

```bash
composer require dorpmaster/nats:^1.0
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

## Docker Examples

Default container command runs Core worker example:

```bash
docker build -t nats-php-driver .
docker run --rm --network <your-network> -e NATS_HOST=nats nats-php-driver
```

Run JetStream worker example by overriding command:

```bash
docker run --rm --network <your-network> \
  -e NATS_HOST=nats \
  -e NATS_PORT=4222 \
  nats-php-driver php ./bin/jetstream_worker.php
```

Run local JetStream test server (from this repository) and execute JetStream example in same network:

```bash
docker compose -f docker/compose.nats.jetstream.test.yml --project-name nats-js-it up -d --wait
docker run --rm --network nats-client-jetstream-test-network \
  -e NATS_HOST=nats-js \
  -e NATS_PORT=4222 \
  nats-php-driver php ./bin/jetstream_worker.php
```

Core worker environment variables:

- `NATS_HOST` (default `nats`)
- `NATS_PORT` (default `4222`)
- `NATS_CONNECT_TIMEOUT_MS` (default `1000`)
- `NATS_LOG_LEVEL` (`debug|info|warning|error`, default `info`)
- `NATS_SUBJECT` (default `demo.jobs`)
- `NATS_REPLY_PAYLOAD` (default `ok`)
- `NATS_PUBLISH_EVERY_MS` (default `0`, disabled)
- `NATS_PUBLISH_PAYLOAD` (default `demo-message`)
- `NATS_RECONNECT_ENABLED` (default `1`)
- `NATS_RECONNECT_ATTEMPTS` (default `10`, `-1`/`null` for infinite)
- `NATS_RECONNECT_BACKOFF_INITIAL_MS` (default `50`)
- `NATS_RECONNECT_BACKOFF_MAX_MS` (default `1000`)
- `NATS_RECONNECT_BACKOFF_MULTIPLIER` (default `2.0`)
- `NATS_RECONNECT_JITTER` (default `0.2`)
- `NATS_RECONNECT_SERVERS` (CSV `host:port`, default empty)

JetStream worker environment variables:

- `JS_HOST` / `JS_PORT` (fallback to `NATS_HOST` / `NATS_PORT`)
- `JS_STREAM` (default `ORDERS`)
- `JS_SUBJECTS` (CSV, default `orders.*`)
- `JS_CONSUMER` (default `C1`)
- `JS_FILTER_SUBJECT` (default `orders.created`)
- `JS_PUBLISH_SUBJECT` (default equals `JS_FILTER_SUBJECT`)
- `JS_PUBLISH_COUNT` (default `10`)
- `JS_PUBLISH_PREFIX` (default `hello-`)
- `JS_CONSUME_MAX` (default `10`, `<=0` means unlimited)
- `JS_CONSUME_BATCH` (default `10`)
- `JS_CONSUME_EXPIRES_MS` (default `1000`)
- `JS_CONSUME_NO_WAIT` (default `0`)
- `JS_CONSUME_MAX_IN_FLIGHT_MESSAGES` (default `100`)
- `JS_CONSUME_MAX_IN_FLIGHT_BYTES` (default `5000000`)
- `JS_SLOW_CONSUMER_POLICY` (`error|drop_new`, default `error`)

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
- `subscribe()/publish()/request()` called before protocol readiness are buffered internally and flushed after the initial `INFO -> CONNECT -> PING/PONG -> READY` barrier.
- The client guarantees that no application-level commands are sent before the connection reaches READY (`INFO -> CONNECT -> PING/PONG`).
- `request()` is not silently retried; timeout/failure can happen during disconnect.
- During reconnect, restored subscriptions are replayed before buffered application publishes are flushed.
- Inbound overflow behavior depends on configured policy (`ERROR` fails fast, `DROP_NEW` drops new messages).
- Outbound overflow behavior depends on `writeBufferPolicy` (`ERROR` throws `WriteBufferOverflowException`, `DROP_NEW` drops new frame).
- Memory usage is bounded only if your configured limits are bounded.
- `drain()` is graceful but bounded by timeout and network state.

## JetStream Admin (MVP)

JetStream administrative API is available through the control-plane transport (`$JS.API.*`).

Supported operations:

- create/get/delete stream
- create-or-update/get/delete pull consumer metadata

See [docs/jetstream-admin.md](docs/jetstream-admin.md).

## JetStream Publisher (MVP)

JetStream publisher sends payload to stream subjects and expects `PubAck` response from the server.

- payload is sent as binary data
- PubAck is parsed from JSON response
- supports dedup and expectations via publish headers

Example:

```php
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;

$publisher = new JetStreamPublisher($client);
$ack = $publisher->publish(
    'orders.created',
    'hello',
    PublishOptions::create(msgId: 'orders-1'),
);
```

See [docs/jetstream-publish.md](docs/jetstream-publish.md).

## JetStream Pull (MVP + Continuous Loop)

JetStream pull consumer supports bounded `fetch()` and continuous `consume()` loop with local backpressure controls.

Example:

```php
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;

$factory = new JetStreamPullConsumerFactory($transport);
$pull = $factory->create('ORDERS', 'C1');
$result = $pull->fetch(batch: 10, expiresMs: 2000);
$acker = $result->getAcker();

foreach ($result->messages() as $message) {
    $acker->ack($message);
}
```

Continuous loop example:

```php
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;

$handle = $pull->consume(new PullConsumeOptions(batch: 10, expiresMs: 1000));
$acker = $handle->getAcker();

while (($message = $handle->next(2000)) !== null) {
    $acker->ack($message);
}
```

Notes:
- `drain(timeout)` waits for both internal queue empty and `inFlight=0`; unacked messages will cause timeout.
- Under `DROP_NEW`, redelivery can happen; if `Nats-Num-Delivered > 5` header is present, dropped message is auto-terminated (`+TERM`).

See [docs/jetstream-pull.md](docs/jetstream-pull.md).

## JetStream Reconnect, Cluster, TLS

JetStream publisher and pull workflows are covered by reconnect/failover integration suites:

- single-node JetStream reconnect handling
- JetStream cluster failover (3-node, replicated streams)
- JetStream cluster + TLS failover (`verifyPeer`, CA, optional mTLS)

See [docs/jetstream-reconnect.md](docs/jetstream-reconnect.md).

## JetStream Logging (PSR-3)

JetStream services accept optional PSR-3 logger dependencies. If logger is omitted, `NullLogger` is used.

```php
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;

$logger = new YourPsrLogger();

$transport = new JetStreamControlPlaneTransport($client, logger: $logger);
$admin = new JetStreamAdmin($transport, $logger);
$publisher = new JetStreamPublisher($client, $logger);
$pullFactory = new JetStreamPullConsumerFactory($transport, logger: $logger);
```

## Testing

```bash
make phpunit
make integration
make integration-tls
make integration-jetstream
make integration-jetstream-cluster
make integration-jetstream-cluster-tls
make integration-cluster
make integration-cluster-tls
make test
```

## Stability & Versioning

- Versioning follows SemVer.
- Public API includes classes and interfaces under `src/Domain/*` and documented entry points (`Client`, `ConnectionConfiguration`, `ClientConfiguration`, protocol message contracts).
- Internal behavior may evolve in minor/patch releases while preserving API compatibility.
- Latest patch release (`1.1.1`) improves lifecycle consistency and state transition event semantics.

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
- [docs/jetstream-admin.md](docs/jetstream-admin.md)
- [docs/jetstream-publish.md](docs/jetstream-publish.md)
- [docs/jetstream-pull.md](docs/jetstream-pull.md)
- [docs/jetstream-reconnect.md](docs/jetstream-reconnect.md)

## License

MIT. See [LICENSE](LICENSE).

## Release

Current stable release: **1.1.1**

See:
- [CHANGELOG.md](CHANGELOG.md)
- [RELEASE_NOTES_1.1.1.md](RELEASE_NOTES_1.1.1.md)
- [RELEASE_NOTES_1.1.0.md](RELEASE_NOTES_1.1.0.md)
- [RELEASE_NOTES_1.0.3.md](RELEASE_NOTES_1.0.3.md)
- [RELEASE_NOTES_1.0.2.md](RELEASE_NOTES_1.0.2.md)
- [RELEASE_NOTES_1.0.1.md](RELEASE_NOTES_1.0.1.md)
