# JetStream streaming backup and recovery V2 (1.4.0)

V2 archives move between file and memory streams. The server generates and
validates the opaque archive; PHP does not decompress it or buffer it in full.
Archive bytes and their metadata are two parts of the same completed backup.
Keep both before attempting recovery from another process.

## Public API

`JetStreamAdmin` implements the optional `JetStreamBackupAdminInterface`:

```php
public function backupStream(
    string $stream,
    Amp\ByteStream\WritableStream $output,
    ?BackupOptions $options = null,
    ?int $timeoutMs = null,
    ?Amp\Cancellation $cancellation = null,
): StreamBackupMetadata;

public function restoreStream(
    string $destination,
    Amp\ByteStream\ReadableStream $input,
    StreamBackupMetadata $backup,
    string $storage,
    ?RestoreOptions $options = null,
    ?int $timeoutMs = null,
    ?Amp\Cancellation $cancellation = null,
): StreamInfo;
```

Types `BackupOptions`, `RestoreOptions` and `StreamBackupMetadata` are in
`Dorpmaster\Nats\Domain\JetStream\Backup`. Existing Admin interfaces and
constructor arguments are unchanged. The concrete Admin accepts wider scalar
entry parameters to reject coerced stream names, storage and deadlines even
from PHP files without `strict_types`. Use actual strings and positive integer
milliseconds. Stream names follow the existing Admin resource-name rules.

The default deadline is 30,000 ms, shared by admission, network waits, input/output
and every queued write. It uses monotonic time, including a synchronous check
at the physical write boundary. A transfer belongs to one connection generation;
it fails on reconnect. Starting a fresh transfer is an explicit application action.

## Requirements and permissions

V2 requires proven target JetStream API level 5 (tested with NATS 2.15.2).
NATS 2.12.6 and 2.14.0 cannot start it. Local INFO is advisory; the driver also
checks account INFO through the configured API prefix and carries
`Nats-Required-Api-Level: 5` on the real SNAPSHOT/RESTORE start request. Unknown
or malformed target capabilities fail before either start command. No separate
request `format`/`version` switch exists on 2.15.2.

Call the archive API after the connection reaches READY (an ordinary account INFO
request can serve as a barrier after asynchronous `connect()`). The transport
must expose current capabilities, `max_payload`, and a client with
`ConnectionBoundProtocolClientInterface`. Missing optional support fails explicitly.
Allow the selected prefix's `INFO`, `STREAM.SNAPSHOT.<source>` and
`STREAM.RESTORE.<destination>`, private inbox subscriptions, snapshot delivery,
`$JS.SNAPSHOT.ACK.<source>.>` publication and the returned
`$JS.SNAPSHOT.RESTORE.<destination>.<token>` publication/replies. The driver
validates upload identity before reading the source. Account/server resource
limits and stream subject overlaps still apply; a new name alone does not permit
overlapping subjects with an existing stream.

## Save bytes and metadata

```php
use Amp\ByteStream\WritableResourceStream;
use Dorpmaster\Nats\Domain\JetStream\Backup\BackupOptions;

$file = new WritableResourceStream(fopen('/backups/orders.nats.part', 'wb'));
try {
    $metadata = $admin->backupStream('ORDERS', $file, new BackupOptions(
        includeConsumers: true,
        chunkSize: 131072,
        windowSize: 1048576,
        checkMessages: true,
    ), timeoutMs: 120000, cancellation: $cancellation);
    $file->end(); // Flush/close your sink before advertising the backup.
    file_put_contents('/backups/orders.metadata.json.part', $metadata->toJson());
    // Publish this pair together using your application's durable storage policy.
} finally {
    $file->close();
}
```

Only a successful return makes the backup complete. Do not publish a `.part`
archive or a metadata file from a previous attempt. `StreamBackupMetadata` is
immutable and exposes `stream`, `config`, `state`, `archiveBytes` and `toJson()`.
`fromJson()` reloads it in a separate process. Both SNAPSHOT and persisted
metadata require a present `config.subjects` to be a JSON array of JSON strings;
null, objects and numeric elements (including large numbers) are rejected.
An absent field remains absent and an empty list remains `[]`. Nested unknown config/state fields,
empty JSON objects/lists, UInt64 tokens and nanosecond timestamps remain exact;
`JsonObject::get()` inspection values must not be used to rebuild the wire JSON.
The driver records byte length, not a cryptographic archive checksum. Protect
and associate both files using your storage's integrity and access controls.

Backup subscribes before SNAPSHOT using the optional lossless protocol channel.
Its nonblocking callback runs in wire order directly into the archive's bounded
FIFO. Application dispatcher byte/count limits and DROP_NEW/ERROR policies do
not discard private archive chunks; ordinary subscriptions keep those policies.
Legacy streaming-only clients fail before INFO rather than silently weakening
archive delivery. Protocol callback errors are reported to the channel owner.
Backup subscribes before SNAPSHOT. Full chunks are validated for stream, size
and ascending ACK index, written once in wire order and ACKed only after the sink
write succeeds. A short tail has no ACK. A successful empty HMSG 204 completes a
normal transfer; a small archive with no full chunks may end with an empty MSG
without status. A 204 without any preceding full chunk is invalid. A start response, partial output, 408/500 or missing terminator
is never success.

`chunkSize` is 1,024..1,048,576 bytes; `windowSize` is at least one chunk and at
most 33,554,432 bytes. The private FIFO has a byte limit of `windowSize + chunkSize`
and at most `floor(windowSize / chunkSize) + 3` messages. One sink write is active
at a time. These are archive-layer bounds, in addition to the configured Client
and Connection inbound queues and the caller's own stream buffering. Overflow
fails explicitly. A slow sink is bounded by the same deadline and server flow
control timeout; ACK never precedes its completed write.

## Restore in another process

```php
use Amp\ByteStream\ReadableResourceStream;
use Dorpmaster\Nats\Domain\JetStream\Backup\RestoreOptions;
use Dorpmaster\Nats\Domain\JetStream\Backup\StreamBackupMetadata;

$metadata = StreamBackupMetadata::fromJson(
    file_get_contents('/backups/orders.metadata.json')
);
$file = new ReadableResourceStream(fopen('/backups/orders.nats', 'rb'), 131072);
try {
    $restored = $admin->restoreStream(
        'RECOVERED_ORDERS', $file, $metadata, 'memory',
        new RestoreOptions(chunkSize: 131072, maxSourceChunkSize: 1048576),
        timeoutMs: 120000,
        cancellation: $cancellation,
    );
    // This is the validated server stream_create_response, not a local synthesis.
    $state = $restored->getState();
} finally {
    $file->close();
}
```

The destination must be new. Storage is exactly `file` or `memory`; config is
copied exactly except for these explicit name/storage overrides. The driver never
deletes a conflicting stream or a partially created destination. It checks the
returned upload subject and waits for each chunk ACK. Upload chunks are capped
by both `chunkSize` and the connection's `max_payload`. At EOF, after the exact
completed archive length, it sends one empty chunk and requires a typed final
`stream_create_response` matching destination and storage.

Use a source whose `read()` returns bounded chunks, such as the resource stream
above. `maxSourceChunkSize` defaults to 1 MiB and may be set up to 32 MiB, at least
`chunkSize`. A source returning a larger chunk fails; a generic AMPHP source cannot
be asked to truncate an already returned chunk. Do not call `buffer()` on a large
archive. Corruption and the server's V2 end sentinel are validated server-side;
the driver also rejects length mismatch before sending EOF.

## Stream ownership and failures

On success the driver neither closes nor ends caller streams. Callers flush,
close and persist them. A source with `isReadable() === false` is rejected before
INFO, subscription or any RESTORE. On preflight failure before archive IO starts, streams are
untouched. On failure after a read/write begins, the driver closes that stream to
abort a pending IO call. Stream implementations must cooperate with close and
AMPHP cancellation. A sink's failing `write()` may already have written some bytes.

Local argument errors are `InvalidArgumentException`. Operation failures are
`JetStreamArchiveException` (a `JetStreamApiException`) with:

- `getOperation()`, `getStream()` and `getTransferredBytes()`;
- `mayHavePartialOutput()` and `isOutcomeUnknown()`;
- original `getPrevious()` cause and server `getApiCode()`, `getErrorCode()`,
  `getDescription()` where present.

Transferred bytes count only completed sink writes or acknowledged upload chunks.
A failing IO call may have changed more bytes than this count. A backup failure
never yields completed metadata. Restore failure after an attempted start/upload
is conservatively unknown unless a correctly typed JetStream error with an integer HTTP error code
400..599, a positive JetStream err_code and a string description confirms refusal
(excluding uncertain timeout/gateway codes 408/504). Missing/string/success codes
cannot confirm a refusal. Wrong response types, timeout/gateway statuses and lost final responses
cannot prove that the destination was not restored. An unsent start fails with
`unknown=false`; genuine server refusal retains its codes. Session cleanup cannot
replace the original cause. No raw archive data, message bodies, credentials or
configuration objects are sent to the logger.

After an unknown result, inspect `STREAM.INFO.<destination>` on a fresh/ready
connection, verify config, sequences and independent message reads, and inspect
consumer state. Coordinate with operators before deleting a partial resource or
retrying under another new name. Automatic retries are disabled for starts,
chunks, flow-control ACKs, EOF and session cleanup frames on a new connection.

## Recovery semantics

V2 includes consumer state fetched from consumer leaders, including an R1 durable
outside an R3 stream leader. `includeConsumers: false` maps to `no_consumers: true`.
Restored pending work may be redelivered after the ACK floor; delivery counts and
pending/redelivery history are not a durable processing ledger. Verify actual
next delivery and ACK with your application, not just INFO counters.

Backup does not freeze concurrent writes, deletes or expiry. Initial first/last
sequence bounds are retained, including gaps, while message/byte counts may differ
as the server generates the archive. TTL can expire records before or after
restore. Quiesce writers/consumers when an application requires a coordinated
recovery point. Archive data contains application payloads and consumer configuration;
protect the saved files appropriately.

## Verification

```bash
make integration-jetstream-backup
make integration-jetstream-backup-cluster
make integration-jetstream-backup-tcp
```

The runner accepts `--image`, repeated pinned `--version` and `--output` paths.
Cluster mode uses three NATS 2.15.2 servers. Tests independently read restored
payloads, repeated headers and sequence gaps; check file/memory portability,
consumer ACK progress, damaged/truncated archives, existing-target safety,
bounded slow-sink memory and lost-final recovery. TCP tests use a peer-side
complete-frame barrier and a post-reconnect control exchange.

Protocol references: [ADR-63](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-63.md)
and [NATS 2.15.2 API implementation](https://github.com/nats-io/nats-server/blob/v2.15.2/server/jetstream_api.go).
