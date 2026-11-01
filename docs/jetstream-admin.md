# JetStream Admin

This module implements JetStream control-plane operations over a configurable API prefix (default `$JS.API`).

Scope:

- stream management (`STREAM.CREATE`, `STREAM.INFO`, `STREAM.UPDATE`, `STREAM.DELETE`)
- consumer management (`CONSUMER.CREATE`, `CONSUMER.INFO`, `CONSUMER.DELETE`)
- no data-plane JetStream publish/pull logic in this MVP

## Run NATS with JetStream

```bash
make js-up
make integration-jetstream
make js-down
```

## Example

```php
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;

$transport = new JetStreamControlPlaneTransport($client);
$admin = new JetStreamAdmin($transport);

$admin->createStream(new StreamConfig('ORDERS', ['orders.*']));
$streamInfo = $admin->getStreamInfo('ORDERS');

$admin->createOrUpdateConsumer('ORDERS', new ConsumerConfig('C1'));
$consumerInfo = $admin->getConsumerInfo('ORDERS', 'C1');

$admin->deleteConsumer('ORDERS', 'C1');
$admin->deleteStream('ORDERS');
```

## Local Validation

Stream and durable consumer names must be nonempty and contain no whitespace,
control characters, `.`, `*`, `>`, `/`, or `\`. Names such as `ORDERS@EU`,
`ORDERS+EU`, and `WORKER@EU`, as well as hyphens and underscores, are supported.
Invalid names raise `InvalidArgumentException` identifying the field before an
API request is sent.

The transport and its PUB/HPUB frame constructors separately reject empty
required command tokens, whitespace, and control characters in subjects and
reply-to values. They preserve valid resource-name characters in compound
JetStream addresses. Binary payloads are preserved byte for byte, and Core
`PubMessage`/`HPubMessage` retain their existing subject rules.

## Error Handling

`JetStreamControlPlaneTransport` throws `JetStreamApiException` when:

- JetStream API response contains `error` object
- request payload cannot be encoded to JSON
- response payload cannot be decoded from JSON

`JetStreamApiException` includes:

- `getApiCode(): int`
- `getDescription(): string`
- `getErrorCode(): ?int` (server `error.err_code`, distinct from `error.code`)

`getApiCode()` and inherited `getCode()` retain `error.code`; description and
exception message retain the server description. The third constructor parameter
is still `previous`; optional `errorCode` is fourth. Malformed JSON/error fields
raise a controlled `JetStreamApiException`, preserving the originating exception
when one is translated. Error descriptions, payloads and original exceptions are
not automatically passed to the logger. Code/err_code extraction is shared by
control-plane requests, single-message publish and the existing pull JSON error
path. Pull still recognizes service errors only on its existing no-ACK reply
path; ordinary messages with ACK subjects keep user JSON payloads unchanged.

## API prefix

```php
$local = new JetStreamControlPlaneTransport($client); // $JS.API
$domain = new JetStreamControlPlaneTransport($client, apiPrefix: '$JS.EU.API.');
$import = new JetStreamControlPlaneTransport($client, apiPrefix: 'IMPORT@EU+API');
```

One optional trailing dot is accepted and removed. The canonical prefix is joined
to the suffix with exactly one dot for both `request()` and `publishRequest()`.
Empty prefixes/tokens, multiple trailing dots, wildcard, whitespace and control
characters fail with `InvalidArgumentException` before client actions. Valid
literal characters such as `@` and `+` are preserved. Normal publish subjects are
unchanged. The prefix is never inferred from INFO `domain`.

See [protocol compatibility](jetstream-compatibility.md) for optional capability
contracts and conservative rules for remote domain/import targets.

## Stream creation options

`StreamConfig` retains `(name, subjects, storage = null, replicas = null)` and
appends nullable `allowAtomicPublish`, `allowBatchPublish`, `maxConsumers`.
Their wire fields are `allow_atomic`, `allow_batched`, `max_consumers`.
Null omits a field, while explicit false and zero are sent. No server defaults
are supplied by the driver. `maxConsumers=-1` means unlimited; NATS also normalizes
zero to -1 (unlimited), rather than prohibiting consumers. Values below -1 fail
locally. Existing CREATE calls emit the same fields as before.

```php
$admin->createStream(new StreamConfig(
    'ORDERS', ['orders.*'], 'file', 1,
    allowAtomicPublish: true,
    allowBatchPublish: false,
    maxConsumers: 10,
));
```

## Updating selected fields (planned 1.3.0)

```php
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;

$updated = $admin->updateStream('ORDERS', new StreamConfigPatch(
    subjects: ['orders.*', 'audit.orders.*'],
    replicas: 1,
    allowAtomicPublish: true,
    maxConsumers: 20,
), timeoutMs: 2000);

$config = $updated->getConfiguration(); // JsonObject, nullable for manually built legacy DTOs
$exactJson = $config?->toJson();
```

A patch supports only subjects, replicas, and the three new options. Null omits a
field; an empty patch, invalid name/pattern, replica count outside 1..5, invalid
consumer limit or nonpositive timeout fails locally before the first request.
Name and storage cannot be patched.

UPDATE reads `STREAM.INFO.<name>`, patches only explicitly selected top-level
fields of its `config`, and sends that configuration to `STREAM.UPDATE.<name>`.
It never sends the INFO response envelope or creates a missing stream. Retention,
limits, metadata, placement, sources/mirror, consumer limits and unknown fields
retain their nested JSON tokens, including empty objects versus lists and exact
large integers/fractional numbers. `JsonObject::get()` returns freshly decoded
values (large integers outside PHP int are decimal strings); `toJson()` and
`withFields()` retain unchanged number tokens without converting them to strings
on the wire. Do not rebuild UPDATE from its inspection array.

The returned `StreamInfo` contains the server response, not a fabricated merge.
Requested fields are checked against that response. NATS omits false boolean
fields, so omission is interpreted as false; zero consumer limit is confirmed by
the server's canonical -1. If a field is not confirmed, including a silently
ignored true flag, `JetStreamConfigurationUnconfirmedException` exposes actual
`getStreamInfo()` and `getFields()`. This applies to newly specified CREATE fields
as well. An older server can explicitly reject an unsupported field instead:
2.12.6 rejects `allow_batched` with code 400 / err_code 10025. No rollback is
attempted; the server may already have applied/created the returned configuration.

INFO → UPDATE is not an atomic merge across clients. A concurrent writer can be
overwritten; the driver offers no protection against lost updates. Both requests
share one monotonic deadline (default 2000 ms), and UPDATE uses the explicit single-attempt Client/transport/write-buffer capability. It is not automatically
retried after an error or uncertain result, including through `failedFrame` after reconnect. A timeout may occur after the server
applied a change; inspect state explicitly before deciding to retry.

### UPDATE write lifetime

Built-in Admin requires `JetStreamSingleAttemptTransportInterface` for UPDATE.
The built-in transport implements it through
`SingleAttemptRequestClientInterface::requestOnce()`, and Client verifies the
optional `SingleAttemptWriteBufferInterface` guarantee before creating an inbox.
Existing interfaces and their methods remain unchanged. Legacy implementations
continue to support ordinary operations, but cannot silently substitute a replayable
request for UPDATE. Direct built-in transport `request()`/`requestJson()` calls
with a `STREAM.UPDATE.*` suffix use the same single-attempt path.

The request SUB and UPDATE wire snapshots have bounded, removable queue lifetimes.
Completion, timeout or cancellation removes unsent snapshots and the local inbox;
its timer and cancellation callbacks are released. An attempted write failure
removes UPDATE from accounting and never saves it for replay in `failedFrame`.
`RequestWriteException` reports an uncertain delivery outcome and chains the
original write exception. Reconnect still runs independently, while the UPDATE
operation completes once. A timeout that wins before a late write error remains
the original result. Bytes already passed to a write cannot be recalled, and an
in-flight attempt is accounted until the write settles; neither timeout nor failure
is evidence that the server did not apply UPDATE. Inspect the stream before making
an explicit retry decision. Cleanup UNSUB frames may still wait behind a paused
writer, but the expired UPDATE and its unsent SUB are withdrawn immediately.

## Metadata snapshots

Stream and consumer results expose immutable [typed metadata](jetstream-metadata.md),
complete exact JSON responses/configurations, nanosecond timestamps and separate
current/desired cluster models. This adds no queries to the existing operation
and does not alter the single-attempt UPDATE lifetime.

When mapping `StreamInfo`, an explicitly present `config.subjects` must be a JSON
array of JSON strings. Numeric elements are invalid even beyond PHP int; a quoted
numeric string remains a string. Both INFO and UPDATE raise `JetStreamApiException`
identifying `subjects` for an invalid field or element. INFO is read before this
validation, and no modifying request is invoked on failure. `null` is invalid.
An absent field is retained as absent (including mirror/source configurations);
an explicit empty list is valid and remains `[]`.
