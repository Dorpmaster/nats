# JetStream Reconnect Semantics

JetStream workflows in this library run on top of the same reconnect/failover logic as core NATS.

## Guarantees

- Delivery semantics are **at-least-once**.
- During reconnect windows, duplicate publish delivery is possible.
- `PubAck` can be lost if transport breaks between server commit and client receive.
- Pull `consume()` loop is reconnect-aware and keeps running after transient disconnects.

## Publisher Recommendations

- Always set `msgId` (`Nats-Msg-Id`) for deduplication.
- Use expected headers (`Nats-Expected-*`) for optimistic concurrency where needed.

## Pull Consumer Behavior

- `fetch()` and `consume()` continue after reconnect/failover when client reconnect is enabled.
- `drain(timeout)` waits for:
  - internal queue to be empty
  - `inFlightMessages == 0`
- If timeout expires before in-flight messages are acknowledged, `JetStreamDrainTimeoutException` is thrown.

## DROP_NEW Guard

With local pull backpressure policy `DROP_NEW`:

- extra messages are not delivered to user code;
- if header `Nats-Num-Delivered` exists and value is greater than `5`, message is auto-terminated (`+TERM`);
- if header is absent, guard is not applied.

## Cluster Notes

- For failover safety, stream replication should be `num_replicas >= 2` (integration tests use `3`).
- Single-node restart without persisted state can invalidate previous stream/consumer metadata.

## TLS Cluster Notes

- `verifyPeer=true` requires valid CA and hostname/SAN match for all failover targets.
- Avoid pinning `serverName` to one node in multi-node failover setup.
