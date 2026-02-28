# Metrics

Metrics are emitted through `MetricsCollectorInterface`:

- `increment(string $counter, int $by = 1, array $tags = []): void`
- `observe(string $metric, float $value, array $tags = []): void`

If collector is not provided, `NullMetricsCollector` is used.

## Counters

- `reconnect_count`
  - successful reconnect (`RECONNECTING -> CONNECTED`)
- `reconnect_backoff_cancelled`
  - reconnect delay cancelled by lifecycle
- `dropped_messages`
  - inbound `DROP_NEW` and outbound `DROP_NEW`
- `slow_consumer_events`
  - inbound overflow under `ERROR`
- `write_buffer_overflow`
  - outbound overflow under `ERROR`
- `ping_timeouts`
  - ping timeout events

## Observations

- `ping_rtt_ms`
  - measured PING/PONG round-trip time

## Example

```php
$collector = new YourCollector();

$config = new ClientConfiguration(
    metricsCollector: $collector,
);
```
