# TLS

TLS is optional and disabled by default.

## Basic TLS

```php
$tls = new TlsConfiguration(
    enabled: true,
    verifyPeer: true,
    caFile: __DIR__ . '/certs/ca.pem',
    serverName: 'nats.internal',
);

$connection = new ConnectionConfiguration(
    host: 'nats.internal',
    port: 4223,
    tls: $tls,
);
```

## Supported Options

- `enabled`
- `verifyPeer`
- `caFile`, `caPath`
- `clientCertFile`, `clientKeyFile`, `clientKeyPassphrase` (mTLS)
- `serverName` (SNI / peer-name override)
- `allowSelfSigned`
- `minVersion` (`TLSv1.0`..`TLSv1.3`)
- `alpnProtocols`

## Failure Model

TLS failures are wrapped into `ConnectionException` with message prefix `TLS handshake failed:`.

## Notes

- Keep `verifyPeer=true` in production.
- Test certificates under `tests/Support/tls/` are for test environments only.
