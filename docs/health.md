# Health / Ping RTT

Health checks are implemented by `PingService`.

## Configuration

`ClientConfiguration`:

- `pingEnabled` (default: `false`)
- `pingIntervalMs` (default: `30000`)
- `pingTimeoutMs` (default: `2000`)
- `pingReconnectOnTimeout` (default: `true`)
- `timeProvider` (default: `MonotonicTimeProvider`)

## Semantics

- Ping loop starts when client enters `CONNECTED`.
- Ping loop stops on leaving `CONNECTED` (`RECONNECTING`, `DRAINING`, `CLOSED`).
- RTT is measured on `PONG` and reported as `ping_rtt_ms`.
- On timeout:
  - `ping_timeouts` is incremented,
  - if `pingReconnectOnTimeout=true`, client starts reconnect flow.

The ping service does not mutate state machine directly; it invokes callback hooks owned by `Client`.
