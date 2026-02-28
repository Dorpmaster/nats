# TLS Support

TLS is opt-in and disabled by default.

```php
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;

$tls = new TlsConfiguration(
    enabled: true,
    verifyPeer: true,
    caFile: __DIR__ . '/certs/ca.pem',
    serverName: 'nats.example.local',
);

$connectionConfig = new ConnectionConfiguration(
    host: 'nats.example.local',
    port: 4223,
    tls: $tls,
);
```

## Supported options

- `enabled` - enable TLS (`false` by default).
- `verifyPeer` - verify remote certificate (`true` by default).
- `caFile` / `caPath` - CA bundle location.
- `clientCertFile` / `clientKeyFile` / `clientKeyPassphrase` - client certificate for mTLS.
- `serverName` - SNI / hostname verification override.
- `allowSelfSigned` - relaxed mode for local environments.
- `minVersion` - minimum TLS version (`TLSv1.0`..`TLSv1.3`).
- `alpnProtocols` - ALPN protocol list.

Connection dialing uses `tcp://host:port` with AMPHP `ClientTlsContext` when TLS is enabled.

## Failure model

TLS connection failures are wrapped into `ConnectionException` with message prefix:

`TLS handshake failed: ...`

## Integration testing

TLS integration tests use a dedicated compose stack and test certificates:

- `make integration-tls`
