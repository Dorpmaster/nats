# Release Notes — v1.4.0

**Release date** 2026-11-03

Applications can schedule JetStream messages, configure sources and mirrors, move replicas, and stream backups between file and memory storage. When upgrading from 1.3.0, choose the features supported by the target JetStream API level.

## Server compatibility

| Operation | Minimum API level | Verified on NATS Server |
| --- | ---: | --- |
| One-time schedule (`@at`), including generated-message TTL | 2 | 2.12.6, 2.14.0, 2.15.2 |
| Recurring schedules, source sampling or scheduled rollup | 4 | 2.14.0, 2.15.2 |
| Sources or mirrors using an application-provided durable consumer, or server-managed links with a WorkQueue or Interest origin | 4 | 2.14.0, 2.15.2 |
| Replica placement and V2 backup/restore | 5 | 2.15.2 |

Basic server-managed sourcing from a Limits origin also works on 2.12.6. The driver checks target capabilities, stream settings and permissions for the operation being requested. [JetStream compatibility](docs/jetstream-compatibility.md)

## Message schedules

Pass a schedule in `PublishOptions::schedule` to publish once at an RFC 3339 timestamp, repeat at a whole-second interval, or use a six-field cron expression. Enable `allow_msg_schedules` on the stream and `allow_msg_ttl` when setting a generated-message TTL. The PubAck confirms that the server stored the schedule definition. Observe the target subject to verify delivery. A recurring definition with a generated-message TTL expires after 365 days. Schedule publication uses the native `$JS.API` scope and requires monitoring access to verify the route before writing. [Schedule options, permissions and examples](docs/jetstream-schedules.md)

## Sources and mirrors

Configure replication with a server-managed consumer or supply an existing durable push consumer on the origin. For an external origin, the driver checks a supplied consumer through the origin API. Updating an unrelated stream limit preserves existing links. Removing a link leaves an application-owned consumer available to its owner. Use stream INFO to inspect observed replication lag and errors. [Sources and mirrors](docs/jetstream-sources.md)

## Replica placement

Use `cancelStreamMove()` to cancel an unfinished reconfiguration, or evacuate a stream peer, a consumer peer or a server. Cancellation can also roll back a replica-count or retention change. Save a stable INFO snapshot before starting the change if you need to verify rollback. Evacuation returns command acceptance. Use the separate INFO observers to confirm the resulting peer group.

Placement commands require the native `$JS.API` namespace and diagnostic access for the authenticated account and SYS. Server evacuation requires a system-account client and can move resources across accounts. If confirmation is lost, inspect placement before issuing another command. [Placement operations and required permissions](docs/jetstream-placement.md)

## Streaming backup and recovery

`backupStream()` writes archive bytes to an AMPHP writable stream and returns metadata containing the source configuration and state. Persist the completed archive and its metadata together. `restoreStream()` reads them into a new destination stream with the chosen file or memory storage. V2 backups can include durable consumers placed outside the stream leader’s node.

Transfers use bounded buffers and a single connection. After a lost final restore response, the destination may already exist. Check its INFO, read a known message and verify consumer progress before deciding whether to retry. [Backup and recovery guide](docs/jetstream-backup.md)

## Upgrade from 1.3.0

After publication, install the release with `composer require 'dorpmaster/nats:1.4.0'`. The Composer requirements match 1.3.0, including PHP `^8.5`, `amphp/byte-stream ^2.1.3`, `ext-event` and `ext-pcntl`. Existing applications can continue using their current interfaces. Applications with a custom client that opt into V2 archives need to implement `ConnectionBoundProtocolClientInterface` for lossless chunk delivery. [Changelog](CHANGELOG.md)
