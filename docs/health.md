# Health Monitoring

Health monitoring is handled by `PingService`.

## Configuration

- `pingEnabled` (default `false`)
- `pingIntervalMs` (default `30000`)
- `pingTimeoutMs` (default `2000`)
- `pingReconnectOnTimeout` (default `true`)
- `timeProvider` (`MonotonicTimeProvider` by default)

## Runtime Model

- ping loop starts when state becomes `CONNECTED`;
- on each interval, service sends PING and waits for PONG;
- RTT is observed as `ping_rtt_ms`;
- timeout increments `ping_timeouts` and can trigger reconnect when enabled.

`PingService` does not mutate state machine directly; it reports timeout through callback and `Client` decides transition.
