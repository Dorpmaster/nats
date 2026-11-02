# JetStream metadata (1.3.0)

Metadata is read from the original ACK subject or the response to an existing
Admin operation. Reading a model performs no network I/O, reset, placement
change or background refresh. Models are immutable snapshots, not live views.

## Message metadata and compatibility

The existing `JetStreamMessageInterface` is unchanged. The built-in
`JetStreamMessage` also implements two independent optional capabilities:

```php
interface JetStreamMessageMetadataInterface {
    public function getMetadata(): ?MessageMetadata;
}
interface JetStreamMessageHeadersInterface {
    /** @return array<string, list<string>> */
    public function getHeaderValues(): array;
}
```

`MessageMetadata` has readonly public properties:

| Property | Type / meaning |
| --- | --- |
| `stream`, `consumer` | `string`, ACK resource names |
| `streamSequence`, `consumerSequence` | canonical decimal `string`, positive UInt64 |
| `deliveryCount` | canonical decimal `string`, positive UInt64 |
| `pending` | canonical decimal `string`, UInt64 including zero |
| `timestamp` | `NanoTimestamp`, original storage time, not redelivery time |
| `domain`, `accountHash` | `?string`, expanded ACK fields |

Supported wire formats (the five numeric fields are positional):

```text
$JS.ACK.<stream>.<consumer>.<delivered>.<sseq>.<cseq>.<ns>.<pending>
$JS.ACK.<domain>.<account>.<stream>.<consumer>.<delivered>.<sseq>.<cseq>.<ns>.<pending>[.<opaque>...]
```

Classic ACKs contain exactly nine tokens and have `domain=null` and
`accountHash=null`. Expanded ACKs have at least eleven tokens. The server's `_`
empty-domain sentinel is exposed as `domain=''`; a named domain is retained.
Additional valid tokens, including `.7`, `.0`, `.token` and multiple tokens,
are opaque and have no effect on metadata. No token-length heuristic is used.
`$JS.FC` and status frames do not produce message metadata.

`getMetadata()` returns `null` when the ACK is absent, unsupported or malformed,
or any required field is outside its range. Payload delivery continues. Leading
zeroes in ACK numeric tokens are accepted and normalized in the model; the
original reply subject remains unchanged for ACK, NAK and TERM.

`getDeliveryCount(): ?int` retains its existing independent validation and range
(`1..PHP_INT_MAX`). A malformed *other* numeric field can therefore make full
metadata unavailable without losing a previously valid legacy delivery count.
For valid built-in pull messages, the two delivery counts agree within PHP int.
Larger valid UInt64 counts remain available in full metadata while the legacy
getter returns `null`. `Nats-Num-Delivered` is always a user header. The existing
DROP_NEW rule is unchanged: automatic TERM applies only to a dropped message
whose legacy count is known and greater than five.

The old constructor arguments and their names are retained. An optional final
`headerValues: ?array` argument was appended to `JetStreamMessage`; old six-argument
calls remain valid. A manually supplied legacy delivery count is still returned
as supplied. `getHeaders()` retains the first-value view. `getHeaderValues()`
provides the normalized header-name order and all values in their received order,
as an immutable array snapshot; it does not retain the mutable `HeaderBag`.
Fetch and consume use the same mapping for MSG and HMSG.

## Exact numbers and timestamps

New counters and sequences are decimal strings. Do not cast them to float or
compare them lexicographically without comparing digit lengths first.

| Field | Range |
| --- | --- |
| ACK delivery count and both sequences | `1..18446744073709551615` |
| ACK pending; StreamState messages, bytes, sequences; SequenceInfo sequences; ConsumerInfo pending; PeerInfo lag | `0..18446744073709551615` |
| ACK timestamp in Unix nanoseconds | `0..9223372036854775807` |
| ConsumerInfo ack-pending, redelivered, waiting; PeerInfo active nanoseconds; desired origin replica count | `0..9223372036854775807` |

JSON counter fields must be actual integer number tokens. Quoted numbers,
negative values, fractions, exponent notation and overflow are rejected. JSON
itself forbids leading zeroes in number tokens. Unknown JSON fields retain their
original tokens, including exponents, through `JsonObject::toJson()` and
`getRawValue(string $field): ?string`. The typed paths never convert counters
through float. `JsonObject::get()` / `toArray()` are convenience decoded views;
use raw JSON when exact token spelling or unknown numeric values matter.

`NanoTimestamp::fromUnixNanoseconds(string): self` accepts the ACK range above.
`NanoTimestamp::fromRfc3339(string): self` validates RFC3339 calendar dates with
zero to nine fractional digits and an explicit timezone. It retains the original
spelling and offset; Admin timestamps can represent the server's year-0001 empty
state time and dates outside the ACK Int64 nanosecond range without saturation.
A present year-0001 timestamp is retained, not confused with an absent field.

```php
$time->getRfc3339();            // exact source, including nanoseconds and offset
$time->getUnixNanoseconds();    // exact signed canonical decimal string
$time->toDateTimeImmutable();   // truncates final 3 submicrosecond digits
```

DateTime conversion discards submicrosecond digits toward the beginning of that
second, including for pre-epoch dates. The exact value remains in the model.

## Admin snapshots

`StreamInfo` preserves `getName()`, `getSubjects()` and `getConfiguration()`.
It adds `getCreated(): ?NanoTimestamp`, `getTimestamp(): ?NanoTimestamp`,
`getDomain(): ?string`, `getState(): ?StreamStateInfo`,
`getCluster(): ?ClusterInfo`, `getMetadata(): ?JsonObject` (configuration metadata)
and `getResponse(): ?JsonObject` (the complete original response).
Its optional fourth constructor parameter is `response: ?JsonObject`.

`StreamStateInfo` has readonly nullable `messages`, `bytes`, `firstSequence`,
`lastSequence`, `firstTime`, `lastTime` and an immutable `raw: JsonObject`.

`ConsumerInfo` preserves `getDurableName()`, `getNumPending(): ?int` and
`getNumAckPending(): ?int`. It adds:

- `getName(): ?string`, `getStreamName(): ?string`;
- `getCreated(): ?NanoTimestamp`, `getTimestamp(): ?NanoTimestamp`;
- `getConfiguration(): ?JsonObject`, `getResponse(): ?JsonObject`, `getMetadata(): ?JsonObject`;
- `getDelivered(): ?SequenceInfo`, `getAckFloor(): ?SequenceInfo`;
- `getPendingCount(): ?string`, `getAckPendingCount(): ?string`,
  `getRedeliveredCount(): ?string`, `getWaitingCount(): ?string`;
- `getCluster(): ?ClusterInfo`.

Its old first three constructor parameters retain their names and positions;
optional `configuration: ?JsonObject` and `response: ?JsonObject` follow them.
The old int count view returns `null` when a server count exceeds PHP int, never
a saturated `PHP_INT_MAX`. Manually supplied old constructor counts retain their
old behavior. `SequenceInfo` has readonly nullable `consumerSequence`,
`streamSequence`, `lastActive` and `raw`.

All snapshot JSON is immutable. Each decoded object returned from `get()` is a
fresh view, so modifying it cannot change the saved model. Full configurations,
unknown response/state/cluster fields, object/array distinctions and large number
tokens are preserved. Exact wire fidelity is guaranteed by the built-in transport
and optional `JetStreamJsonTransportInterface`. A legacy array-only transport
uses the schema to restore known object positions (including empty/numeric-key
configuration metadata, state, cluster, desired/origin/status and replica items).
Lists such as subjects and replicas stay lists. This cannot recover unknown
object/array distinctions or numeric precision already lost by that transport's
own decoder. Invalid known floating-point or quoted count values are rejected
rather than guessed. The raw JSON path stays strict: metadata `{}` is an object,
whereas metadata `[]` is rejected. Text fields are checked against the original
JSON token type before decoding, so even an unquoted integer beyond PHP int is
rejected as text; the same digits in a quoted unknown status remain a string.

Missing or explicit-null optional fields produce `null`; a present zero produces
`'0'`. Malformed known fields from Admin raise `JetStreamApiException` identifying
the field and preserving the validation error as `previous`. Constructing a typed
snapshot directly with malformed JSON fields raises `InvalidArgumentException`.
No response bodies or complete models are automatically logged.

## Current and desired cluster state

Each new cluster/nested model takes one `raw: JsonObject` constructor argument.
All properties are readonly and nullable unless `raw` is specified:

| Model | Properties |
| --- | --- |
| `ClusterInfo` | `name`, `raftGroup`, `leader`, `leaderSince`, `systemAccount`, `trafficAccount`, `replicas: ?list<PeerInfo>`, `desired: ?DesiredClusterInfo`, `raw` |
| `PeerInfo` | `name`, `peerId`, `current`, `offline`, `activeNanoseconds`, `lag`, `pending`, `raw` |
| `DesiredClusterInfo` | `created`, `name`, `replicas: ?list<DesiredPeerInfo>`, `origin: ?DesiredClusterOrigin`, `status: ?DesiredClusterStatus`, `raw` |
| `DesiredPeerInfo` | `name`, `peerId`, `offline`, `raw` |
| `DesiredClusterOrigin` | `replicas`, `placement: ?JsonObject`, `retention`, `raw` |
| `DesiredClusterStatus` | `type`, `description`, `error` (wire `err`), `raw` |

The two peer models deliberately have different schemas. Selected desired peers
need not expose `current`, `lag` or activity. Unknown status type strings are
retained. Description is diagnostic text and is never parsed for state.
Absent desired state on an older server does not establish that an operation
completed. A missing replica list is `null`, while a present empty list is `[]`.
These are observational models; they do not manage placement or migrations.

Schemas are pinned to [StreamInfo / cluster structures in NATS 2.15.2](https://github.com/nats-io/nats-server/blob/v2.15.2/server/stream.go)
and [ConsumerInfo / SequenceInfo in NATS 2.15.2](https://github.com/nats-io/nats-server/blob/v2.15.2/server/consumer.go).
The ACK layouts follow the [nats.go 1.54 parser](https://github.com/nats-io/nats.go/blob/v1.54.0/internal/parser/parse.go).

## Example

```php
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageMetadataInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageHeadersInterface;

$result = $consumer->fetch(batch: 10, expiresMs: 2000);
foreach ($result->messages() as $message) {
    if ($message instanceof JetStreamMessageMetadataInterface && ($meta = $message->getMetadata()) !== null) {
        $sequence = $meta->streamSequence;           // exact decimal string
        $storedAt = $meta->timestamp->getRfc3339();  // retains nanoseconds
    }
    if ($message instanceof JetStreamMessageHeadersInterface) {
        $allValues = $message->getHeaderValues();
    }
    // Process payload.
    $result->getAcker()->ack($message);
}

$info = $admin->getConsumerInfo('ORDERS', 'C1');
$pending = $info->getPendingCount();
$configurationJson = $info->getConfiguration()?->toJson();
$desired = $admin->getStreamInfo('ORDERS')->getCluster()?->desired;
```

## Verification

```bash
make phpunit
make phpcs
make test
make integration-jetstream
make integration-jetstream-metadata
make integration-jetstream-metadata-cluster
make integration-jetstream-cluster
make integration-jetstream-cluster-tls
# Optional retained raw responses:
python3 tests/Support/run-jetstream-metadata.py --output /absolute/path/to/evidence
python3 tests/Support/run-jetstream-metadata.py --cluster --output /absolute/path/to/cluster-evidence
```

The matrix tests classic ACK on 2.12.6, 2.14.0 and 2.15.2; expanded ACK with named
and empty domains on 2.14.0 and 2.15.2 using `feature_flags {js_ack_fc_v2: true}`.
2.12.6 does not implement that flag or the expanded producer format; this is an
explicit server limitation rather than a skipped test claiming v2 coverage.
The cluster fixture controls only the isolated test environment, not product
placement APIs. It holds a replica transition with a confirmed pause of two new peers and one old follower in a seven-node
cluster. R3 retains two voters and metadata retains four; R5 needs a third
stream voter and cannot finish until the barrier is released and verifies
mapping before release and convergence afterward.
