# Cluster Discovery and Failover

## Seed Servers

Client can be configured with seed servers:

```php
new ClientConfiguration(
    reconnectEnabled: true,
    servers: [
        new ServerAddress('n1', 4222),
        new ServerAddress('n2', 4222),
        new ServerAddress('n3', 4222),
    ],
);
```

## Discovery via `INFO.connect_urls`

Incoming `INFO` frames may include `connect_urls`. Valid entries are converted to `ServerAddress` and added to the server pool with deduplication.

## Failover Algorithm (v1)

1. Reconnect attempts current server first.
2. If `open(current)` fails, current is marked dead for `deadServerCooldownMs`.
3. Next server is selected from pool round-robin, skipping dead entries.
4. On successful open, that server becomes current.

## Cooldown

`deadServerCooldownMs` controls temporary exclusion of failed servers. After cooldown expiration, server can be selected again.

## Limitations

- Core NATS only (no JetStream workflow in reconnect logic).
- At-least-once behavior during reconnect windows can produce duplicate publishes.
