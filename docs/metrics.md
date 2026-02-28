# Metrics Hooks

Client metrics are exposed through a neutral collector interface:

- `Dorpmaster\Nats\Domain\Telemetry\MetricsCollectorInterface`
- methods:
  - `increment(string $counter, int $by = 1, array $tags = []): void`
  - `observe(string $metric, float $value, array $tags = []): void`

By default the client uses `NullMetricsCollector` (no-op).

## Emitted counters

- `reconnect_count`
  - incremented when reconnect succeeds (`RECONNECTING -> CONNECTED`)
- `reconnect_backoff_cancelled`
  - incremented when reconnect backoff is cancelled by lifecycle
- `dropped_messages`
  - inbound: slow consumer `DROP_NEW`
  - outbound: write buffer `DROP_NEW`
- `slow_consumer_events`
  - inbound slow consumer with `ERROR` policy
- `write_buffer_overflow`
  - outbound overflow with `ERROR` policy
- `ping_timeouts`
  - ping timeout events

## Emitted observations

- `ping_rtt_ms`
  - round-trip time measured by `PingService`

## Example

```php
$collector = new YourMetricsCollector();

$clientConfig = new ClientConfiguration(
    metricsCollector: $collector,
);
```

