# JetStream sources and mirrors (1.4.0)

A receiving stream copies messages asynchronously from an origin stream. A
successful Admin CREATE/UPDATE confirms configuration, **not delivery**. Inspect
the link state and read the receiving stream using another client before treating
messages as copied. Deletions on the origin are not replicated as deletions.

## Server-managed sourcing

```php
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamSource;

$admin->createStream(new StreamConfig('ORIGIN', ['orders.>'], 'file', 1));
$admin->createStream(new StreamConfig(
    'AGGREGATE', [], 'file', 1,
    sources: [new StreamSource('ORIGIN', filterSubject: 'orders.eu.*')],
));
$admin->createStream(new StreamConfig(
    'REPLICA', [], 'file', 1,
    mirror: new StreamSource('ORIGIN'),
));
```

A mirror has one origin and cannot have listen subjects or sources. It retains
origin sequence numbers and timestamps. Sources may aggregate several origins
and local listen subjects; order is retained within each origin, but the driver
does not promise a global order across origins. Without a `consumer` reference,
NATS manages the sourcing consumer. Basic sourcing/mirroring of a `limits` origin
works on all three tested releases: 2.12.6, 2.14.0 and 2.15.2.

`StreamSource` optionally accepts `startSequence` (an actual nonnegative PHP int),
`startTime` (RFC3339, up to nanosecond precision) or `filterSubject` (a subscription
pattern). Start sequence and time are mutually exclusive. Sequence zero omits
`opt_start_seq`. This request argument is limited to PHP int; observed UInt64
metadata, described below, remains exact over the full UInt64 range. All resource
names and subjects are validated before client actions. Constructor parameters
are appended to existing StreamConfig/ConsumerConfig/StreamConfigPatch signatures;
existing named arguments and pull consumer calls retain their behavior.

## Bring an existing durable consumer

```php
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamSourceConsumer;

$admin->createOrUpdateConsumer('ORIGIN', new ConsumerConfig(
    durableName: 'COPY_EU',
    ackPolicy: 'flow_control',
    deliverSubject: 'delivery.orders.eu',
    flowControl: true,
    idleHeartbeatMs: 1000,
    maxAckPending: 1000,
));
$link = new StreamSource('ORIGIN', new StreamSourceConsumer(
    name: 'COPY_EU',
    deliverSubject: 'delivery.orders.eu',
));
$admin->createStream(new StreamConfig('COPY', [], 'file', 1, sources: [$link]));
// Or use mirror: $link when creating a separate mirror stream.
```

The application creates this durable **push** consumer first. `flow_control` ACK
policy requires push delivery, flow control, a 1-second heartbeat, a positive
maxAckPending and no ack wait. When omitted, the new typed flow_control path sends
flow_control=true, idle_heartbeat=1000000000 and max_ack_pending=1000. An explicit
incompatible value fails locally. ConsumerConfig also supports optional consumer
replicas; this does not change existing pull consumer defaults.

StreamSourceConsumer emits `consumer.name` and `consumer.deliver_subject`.
The delivery subject must be concrete. A provided consumer cannot be combined
with source startSequence, startTime or filterSubject, including an explicitly
supplied zero sequence. Configure these choices on the consumer instead. Reusing
the same origin/consumer pair in one sources list is rejected, even when the
requested delivery subjects differ. Separate origin names may use the same
consumer name. Coordinate shared consumer ownership across receiving streams;
NATS establishes delivery state when attaching or reattaching it.

## API compatibility and admission

Explicit durable sourcing and `ack_policy=flow_control` need **API level 4**
(NATS 2.14+), exposed as `Feature::DURABLE_SOURCING`. A known older current server
is rejected before a control write. The receiving stream is not silently created
as a basic source/mirror. On an unknown/imported/domain API prefix, the driver
reads the target's account `INFO.api.level`; it does not infer the target version
from the connection endpoint. Missing diagnostics or a missing/incorrect API
level fail closed. New durable operations therefore require account INFO access.

Before a stream CREATE or a link-changing UPDATE using a provided consumer,
Admin reads its INFO through the origin API (`external.api` when present). It checks origin/name/durable identity,
deliver_subject, ACK policy, flow control and heartbeat. This requires permission
for CONSUMER.INFO on the origin as well as stream CREATE/UPDATE on the receiver.
A missing consumer preserves the actual server code/err_code/description; a
configuration mismatch fails locally before the changing request. Admin does not
create/reset/delete the provided consumer as part of its own preflight. NATS may
reset delivery state when establishing the link; plan attachment with the owner.

The checked releases support server-managed sourcing from `workqueue`/`interest`
origins starting with 2.14. These consumers are visible through CONSUMER.INFO/LIST
and their metadata identifies the receiving stream. On Limits streams, internal
server-managed consumers are normally hidden. For a new or changed server-managed
link, the driver reads the actual origin INFO
to select the mode. Confirmed Limits origins retain basic compatibility. Interest
and WorkQueue require a confirmed API level 4 on the receiver and origin. This
applies equally to `$JS.API`, domain and imported prefixes: UNKNOWN is not a
permission to send CREATE/UPDATE. The server does not define those modes on
2.12.6. Origin checks use `external.api` when that field is present; an unavailable
or malformed origin/prefix is rejected before a changing request. The basic Limits
mode is distinct from the new durable mode.

## Observe configuration and actual state separately

```php
$info = $admin->getStreamInfo('COPY');
$config = $info->getConfiguration(); // original JSON, including unknown nested fields
$requested = $config->get('sources');
$observed = $info->getSources(); // ?list<StreamSourceInfo>; mirror uses getMirror()
foreach ($observed ?? [] as $source) {
    echo $source->name, ' lag=', $source->lag ?? 'unknown';
    if ($source->error !== null) {
        // StreamSourceError: nullable code, errorCode (wire err_code), description.
        echo $source->error->description;
    }
}
```

StreamSourceInfo is an immutable observation with `raw`, `name`, `lag`,
`activeNanoseconds`, `sequence` and `error`. Lag and sequence are exact unsigned
decimal strings; sequence is null when the server does not include `seq`. Active
is an exact signed Int64 nanosecond duration; **-1 means not yet active**, rather
than a timestamp. Zero remains distinct from absence. Absent root sources returns
null and an explicitly empty array returns []; raw retains other fields. These
getters do not reconstruct or replace the requested configuration.

For a visible consumer, use `getConsumerInfo(origin, consumerName)`: its N2 fields
include delivered/ACK floor, pending/redelivery counts, cluster information,
metadata and the original configuration/response. Server-generated consumers may
include `_nats.src.stream` or `_nats.mirror.stream` metadata; don't derive their
names or ownership from a name prefix alone.

## Update or remove a link

```php
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;

$admin->updateStream('COPY', new StreamConfigPatch(maxConsumers: 7));
// Unspecified links, nested values and unknown fields survive unchanged.
$admin->updateStream('COPY', new StreamConfigPatch(sources: []));
$admin->updateStream('REPLICA', new StreamConfigPatch(mirror: false));
```

INFO snapshots and update instructions have different schemas. An explicit
`config.mirror: null` or `config.sources: null` in incoming INFO is rejected with
`JetStreamApiException` naming the field before any UPDATE. A valid snapshot omits
an absent link (or supplies a valid object/list); these null snapshots cannot
accidentally become removal commands. A normal stream with neither field
can still update its limits. Before UPDATE, an observed active mirror or source
list also requires its configuration field to be present and nonempty; a
contradictory incomplete snapshot cannot silently remove a link. Each observed
source must also match a distinct entry in the original configuration by origin
name and, when reported, external API prefix, filter subject and subject
transforms. Element order and changing lag/sequence/activity do not affect this
check. Missing or replaced members of a nonempty list cause
`JetStreamApiException` naming `config.sources` before UPDATE. The comparison uses
the original INFO before applying a patch, so explicit link removal remains
available with a trustworthy snapshot. Optional identity details omitted by the
server cannot be checked; observations do not reconstruct the configuration.

An unrelated limit update preserves each link field's original JSON token and
does not revalidate its consumer or require additional origin permissions. This
also applies to existing cross-domain links, even if a same-named incompatible
consumer exists on the receiving side. New or replaced link fields are admitted
separately: their origin INFO/consumer checks use the declared `external.api`
prefix, never an identically named resource in the receiver. Unknown nested
fields and exact numbers in unpatched links remain untouched.

For patch arguments, null omits a field. `sources: []` explicitly clears sources;
`mirror: false` sends JSON null and promotes a mirror to a normal stream. Wrong
original types (for example integer zero for mirror) are not coerced into removal.
Replacing an existing mirror configuration or adding a mirror to an existing
normal stream is rejected by NATS; its server error is propagated. Create a new
mirror if a different origin/configuration is needed. UPDATE retains the N1
single-attempt lifetime and does not replay an uncertain request after reconnect.

Admin only changes the receiving stream. It sends no CONSUMER.DELETE for link
removal. An application's provided consumer remains accessible after removal.
NATS cleans up its own durable sourcing consumer when a managed link is removed;
the integration matrix verifies that lifecycle on Interest retention, including
its receiver metadata. Coordinate removal with in-flight copies. Removing a link
does not erase messages already stored in the receiver, or delete its origin.

Run `make integration-jetstream-sources` for the three-version matrix and
`make integration-jetstream-sources-cluster` for R3 source/mirror leader changes.
Use `make integration-jetstream-sources-external` for the real ORIGIN/DEST leaf
regression, with and without a misleading same-named consumer in DEST.
The runners support `--output /absolute/directory`, with CREATE/UPDATE bodies,
server INFO snapshots and independent receiving-stream reads kept separately.

Protocol references: [source/mirror concepts](https://github.com/nats-io/nats.docs/blob/master/nats-concepts/jetstream/source_and_mirror.md),
[pinned 2.14 stream configuration](https://github.com/nats-io/nats-server/blob/v2.14.0/server/stream.go)
and [durable sourcing server tests](https://github.com/nats-io/nats-server/blob/v2.14.0/server/jetstream_cluster_3_test.go).
