# Cluster + TLS

## Requirements

- Node hostnames in cluster config must match certificate names used for peer verification.
- `verifyPeer=true` requires valid CA chain and hostname/SNI alignment.
- For mTLS, server and client must trust the same CA and client certificate must be configured.

## Configuration

```php
$connection = new ConnectionConfiguration(
    host: 'n1',
    port: 4222,
    tls: new TlsConfiguration(
        enabled: true,
        verifyPeer: true,
        caFile: __DIR__ . '/certs/ca.pem',
        clientCertFile: __DIR__ . '/certs/client.pem',
        clientKeyFile: __DIR__ . '/certs/client-key.pem',
    ),
);

$client = new ClientConfiguration(
    reconnectEnabled: true,
    servers: [
        new ServerAddress('n1', 4222, true),
        new ServerAddress('n2', 4222, true),
        new ServerAddress('n3', 4222, true),
    ],
);
```

## Behavior

- Discovery still works from `INFO.connect_urls`.
- Reconnect/failover uses server pool with dead cooldown.
- Resubscribe is performed after reconnect.
- With `bufferWhileReconnecting=true`, outbound queue can survive short node outage.
- JetStream workflows (publisher/pull) use the same reconnect/failover transport path.

## JetStream Under TLS

- For stable failover in JetStream, use replicated streams (`num_replicas >= 2`, tests use `3`).
- Pull consume loop remains reconnect-aware under TLS.
- `verifyPeer` must succeed for every target node selected by failover.

## Typical Misconfigurations

- CN/SAN mismatch with target hostname.
- Wrong CA file.
- Static `serverName` pinned to a single node while failover targets different node names.
