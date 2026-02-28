# Cluster TLS Failover

Cluster TLS integration uses a 3-node NATS cluster (`n1`, `n2`, `n3`) with mutual TLS.

## Requirements

- seed hosts must match certificate hostnames (`n1`, `n2`, `n3`)
- `verifyPeer` should be enabled
- CA bundle must trust all node certificates
- client certificate/key are required (`verify: true` on server)

## Client TLS Setup

```php
$tls = new TlsConfiguration(
    enabled: true,
    verifyPeer: true,
    caFile: __DIR__ . '/tests/Support/tls/cluster/ca.pem',
    clientCertFile: __DIR__ . '/tests/Support/tls/cluster/client.pem',
    clientKeyFile: __DIR__ . '/tests/Support/tls/cluster/client-key.pem',
    serverName: 'n1',
);
```

## Semantics

- discovery still uses `INFO.connect_urls`
- reconnect failover switches from dead node to next available node
- subscriptions are restored after reconnect
- with `bufferWhileReconnecting=true`, outbound queue can survive short reconnect windows
- duplicate publishes are still possible during reconnect windows; handlers should remain idempotent

## Run

- `make cluster-tls-up`
- `make integration-cluster-tls`
- `make cluster-tls-down`
