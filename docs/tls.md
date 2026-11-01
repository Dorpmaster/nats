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

## Self-signed certificates

`allowSelfSigned` defaults to `false`. Set it to `true` explicitly to permit a
self-signed peer certificate. With `verifyPeer=true`, certificate validity and
server-name verification remain enabled (`verify_peer=true`,
`verify_peer_name=true`). An expired certificate, a mismatched name, or a leaf
issued by an untrusted CA still fails verification. This flag does not lower
OpenSSL's security level or change minimum TLS versions, CA settings, ALPN or
client certificates. `verifyPeer=false` retains the explicitly unverified mode
and disables both certificate and name verification independently of this flag.

The PHP `allow_self_signed` option is applied to the socket stream before TLS
handshake, on every new transport including reconnect and failover. The selected
`ServerAddress::getServerName()` takes precedence over `TlsConfiguration`'s
`serverName`, followed by the selected host (or the configured host when no
address has been selected).

AMPHP `ClientTlsContext` has no method for this option. The connection uses the
public `Amp\ByteStream\ResourceStream::getResource()` capability, available in
`amphp/socket` 2.3.0. A third-party socket without a PHP stream resource cannot
honor `allowSelfSigned=true` and is rejected with `ConnectionException`. With the
flag disabled, sockets without that capability keep their existing TLS setup.
For stream sockets the flag is explicitly set to `false` when disabled.

## Failure Model

TLS configuration and handshake failures are wrapped into `ConnectionException`
with message prefix `TLS handshake failed:`. The unsuccessful transport is closed
and detached, even if socket cleanup itself fails. The next attempt opens a new
socket. Logs contain safe stage/type metadata, without raw connection objects,
credentials, payloads or exception messages.

## Certificate regression matrix

Run `make integration-tls-self-signed` against the pinned NATS Server 2.15.2.
The isolated runner covers self-signed, expired, trusted and untrusted CA-issued
certificates, wrong names, explicit verification disablement, weak keys, mTLS,
failed-handshake recovery, repeated connect, and failover with subscription replay.
It inspects the actual PHP stream options immediately before enabling crypto and
checks two requests through each successful connection. The fixtures and their
validity periods are described in `tests/Support/tls/self-signed/README.md`.
To test another already-built client image, run
`python3 tests/Support/tls/run-self-signed.py --image IMAGE`.

## Notes

- Keep `verifyPeer=true` in production.
- Test certificates under `tests/Support/tls/` are for test environments only.
