# Security Policy

## Supported Versions

Security fixes are provided for the latest release candidate and stable release lines.

| Version | Supported |
| --- | --- |
| 1.0.x | Yes |
| < 1.0.0 | No |

## Reporting a Vulnerability

Please report security issues privately by opening a GitHub security advisory in this repository:

- https://github.com/Dorpmaster/nats/security/advisories/new

If advisory workflow is unavailable, open a private report via repository maintainers and avoid public issue disclosure until a fix is released.

## TLS Notes

- Keep `verifyPeer=true` in production.
- Use trusted CA bundles and valid hostnames/SAN entries.
- Test certificates shipped under `tests/Support/tls/` are for tests only and must not be used in production.
