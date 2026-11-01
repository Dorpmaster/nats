# JetStream Publisher (MVP)

`JetStreamPublisher` publishes payloads to JetStream-enabled subjects and expects `PubAck` from server.

## Basic Publish

```php
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;

$publisher = new JetStreamPublisher($client);
$ack = $publisher->publish('orders.created', 'hello');

$ack->getStream(); // ORDERS
$ack->getSeq();
$ack->isDuplicate();
```

## Deduplication with Msg-Id

```php
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;

$options = PublishOptions::create(msgId: 'orders-42');
$ack = $publisher->publish('orders.created', 'payload', $options);
```

Publish with the same `msgId` within dedup window returns `PubAck` with `duplicate=true`.

## Expected Headers (Optimistic Constraints)

`PublishOptions` supports JetStream expectation headers:

- `expectedStream` -> `Nats-Expected-Stream`
- `expectedLastSeq` -> `Nats-Expected-Last-Sequence`
- `expectedLastMsgId` -> `Nats-Expected-Last-Msg-Id`

If expectation does not match server state, `JetStreamApiException` is thrown.

## Error Model

- API error response (`{"error": ...}`) -> `JetStreamApiException`
- invalid PubAck JSON -> `JetStreamApiException`
- timeout -> `JetStreamTimeoutException` (`code=408`)

## Reconnect Notes

Publisher follows core client reconnect semantics:

- delivery is at-least-once under reconnect windows
- ack can be lost on network failures
- `msgId` is recommended to reduce duplicate effects

## Logging

`JetStreamPublisher` accepts optional `Psr\Log\LoggerInterface`.
If logger is not provided, `NullLogger` is used.

Event keys:
- `js.publish.start`
- `js.publish.retry`
- `js.publish.ack`
- `js.publish.timeout`
- `js.publish.error`

## Atomic batch publication (planned 1.3.0)

`JetStreamPublisher` implements the optional `JetStreamAtomicPublisherInterface`.
Existing `JetStreamPublisherInterface`, single-message publishing and constructors
remain compatible. Enable the stream explicitly through Admin first:

```php
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchCommitMode;

$admin->createStream(new StreamConfig(
    'ORDERS', ['orders.*'], 'file', 3, allowAtomicPublish: true,
));
$ack = $publisher->publishAtomicBatch('ORDERS', [
    ['subject' => 'orders.created', 'payload' => "binary\0payload",
     'headers' => $headerBag],
    ['subject' => 'orders.audit', 'payload' => ''],
], timeoutMs: 5000);

$ack->getStream();
$ack->getBatchId();
$ack->getCount(); // user messages only
$ack->getSeq(); // canonical decimal string, exact through UInt64_MAX
$ack->getResponse(); // immutable complete JsonObject

$options = new AtomicBatchOptions(commitMode: AtomicBatchCommitMode::EOB);
```

The optional interface declares:

```php
publishAtomicBatch(
    string $stream,
    array $messages,
    ?AtomicBatchOptions $options = null,
    ?int $timeoutMs = null,
): AtomicBatchAck;
```

`messages` must be a nonempty PHP list, in publication order. Each entry is an
array with actual string `subject` and `payload`, plus optional `headers` as a
HeaderBugInterface (including HeaderBag) or an array of string header names to
string/list-of-string values. Payloads may be empty or contain arbitrary bytes.
Subjects must be concrete, nonempty tokens separated by dots, without whitespace,
control characters, empty tokens or wildcards. Existing Core wildcard rules are
unchanged. Every frame sets `Nats-Expected-Stream` to enforce the requested stream.

The whole batch is validated and its HPUB bytes captured before publishing any
frame. Later changes to original HeaderBag instances or copies obtained from a
frame getter do not alter the batch. Header value order and repeated values are
preserved with the existing HeaderBag normalization. User `Nats-Batch-*` names,
case-insensitively, and driver-owned `Nats-Expected-Stream` and
`Nats-Required-Api-Level` are reserved and fail locally. Other publish conditions,
such as `Nats-Expected-Last-Sequence`, are passed to the server; all-or-nothing
consistency checks are made at commit. Target confirmation refuses an atomic-disabled
stream locally before the batch; the publisher does not change stream configuration.

The default mode MESSAGE stores the final user message with
`Nats-Batch-Commit: 1`. A one-message batch starts and commits in the same frame.
EOB sends an additional empty frame on the last user subject, sequence count+1,
with `Nats-Batch-Commit: eob`. That marker is not a stored user message. The
publisher waits for each zero-byte staging acknowledgement before sending the
next frame. Only a final PubAck with matching stream, batch ID, message count
and a valid positive UInt64 sequence confirms the operation. Empty intermediate
MSG responses are parsed normally and cannot confirm a commit.

Known current-connection `Feature::ATOMIC_PUBLISH` support (API level 2) is
required. Explicit EOB additionally requires `Feature::ATOMIC_EOB` (API level 4).
Unknown or unsupported capability is rejected before the first batch frame; there is
no speculative feature probe. Required API level is also sent in every frame.
Ordinary commit is covered on NATS 2.12.6, 2.14.0 and 2.15.2; EOB on 2.14.0 and
2.15.2. Explicit EOB on known 2.12.6 sends zero frames.

`AtomicBatchOptions` has immutable `commitMode` and optional per-operation AMPHP
`cancellation`. One monotonic timeout (default 2000 ms, positive integer) covers
validation, all frames and acknowledgements. Concrete implementation accepts the
original timeout through `mixed` to reject weak-caller float/bool coercion. A
cancelled operation does not cancel another batch using the same client.

The built-in Client supplies optional `ConnectionBoundRequestClientInterface`:

```php
requestOnceOnConnection(
    PubMessageInterface|HPubMessageInterface $message,
    int $connectionId,
    float $timeout = 30,
    ?Cancellation $cancellation = null,
): MsgMessageInterface|HMsgMessageInterface;
```

It reuses N1's single-attempt lifetime, withdraws unsent request and inbox snapshots
on cancellation, and aborts when its original ready transport ends. Client owns
the transport cancellation; asynchronous INFO on the same connection does not
create a new transport. ConnectionBoundRequestException exposes `wasAttempted()`
and chains the original cause. Ordinary requestOnce and replayable publication
retain their existing behavior. A custom client must implement this optional
capability and provide current ServerInformation to use atomic publication;
legacy clients continue to support existing operations.

Once a connection is lost, no more frames of that operation are sent, including
a queued continuation or commit. Reconnect proceeds independently and does not
replay a batch. Staged messages are not committed on an application error;
uncommitted batches eventually expire under the server's staging timeout.

`AtomicBatchException` extends JetStreamApiException and provides `getBatchId()`
and `isOutcomeUnknown()`. A validated JetStream JSON client rejection preserves code, err_code
and description and is definitive. Status-only or infrastructure errors do not confirm rejection. If a commit write began and no reliable final
acknowledgement was received, outcome is unknown, including timeout, disconnect,
uncertain write and malformed acknowledgement. The message includes the batch ID,
and previous preserves the original cause. A withdrawn commit that never began
writing is not uncertain. The flag concerns commit confirmation; it is not a
promise about arbitrary external publishers or a reason to retry automatically.
Only a valid `AtomicBatchAck` establishes successful commitment of all messages.

Diagnostics use `js.atomic.start`, `js.atomic.staged`, `js.atomic.committed` and
`js.atomic.failed`, with safe batch ID/count/sequence/error codes. They omit raw
frames, headers, payloads and original exceptions. Run
`make integration-jetstream-atomic` and
`make integration-jetstream-atomic-cluster` for the pinned matrix and R3 checks.
The runner accepts `--output /absolute/directory` to save explicit synthetic wire
traces and independent stream observations separately from production logging.


Atomic target confirmation performs read-only `PUB $JS.API.STREAM.INFO.<stream>`
and `PUB $JS.API.STREAM.NAMES` for each distinct concrete subject. The response
must identify the requested stream, advertise the required server API level in
`config.metadata._nats.level`, enable `allow_atomic`, and resolve every subject
uniquely to that stream. These reads alone cannot certify the publish path:
account subject mappings transform the actual command subject, not a subject
inside the STREAM.NAMES JSON request.

Before the first batch HPUB, the publisher therefore requires server monitoring
access on the same connection. `$SYS.REQ.USER.INFO` identifies its current account
and local server. For that server and every cluster peer found through ROUTEZ,
the publisher reads `$SYS.REQ.SERVER.<id>.VARZ`, `ACCOUNTZ` (with that account),
and `ROUTEZ`. Every response must identify its requested server. Inventory is
bounded to 128 distinct servers, every route peer must be checked, and every
member must advertise the selected mode's API level. No proof is cached across
calls or reconnects.

The supported topology is restricted to a cluster with no configured or active
leaf/gateway exits. The current account must be complete and unexpired on every
member, with no matching subject mappings or matching account imports/exports
for any batch subject. Matching weighted/identity mappings are also rejected;
the publisher does not guess their destination. Unrelated mappings and the
normal JetStream/system API imports are permitted. Mapping property names remain
exact strings, including numeric names; `0` and `00` are distinct subjects. An
unrelated numeric mapping such as `123` does not block other batch subjects. This restriction also excludes
a mapping on a different cluster member, even if STREAM.INFO and STREAM.NAMES
on the connected server would otherwise succeed.

Atomic publication requires permission for these diagnostic reads, normally
provided with system-account service exports and imports. A runnable synthetic
configuration is in [atomic-monitoring.conf](../tests/Support/atomic-monitoring.conf).
Missing permissions, unavailable monitoring, incomplete or mismatched responses,
unsupported members, and unverified routes cause refusal with **zero batch HPUB**;
there is no unchecked fallback or flag asserting that the topology is safe.
Monitoring replies can contain account information and are never logged as raw
payloads. These grants are not required by ordinary single-message publication.

All reads are connection-bound single-attempt requests, use operation cancellation
and consume the same monotonic batch deadline. User headers are copied before
any reads. Atomic publication currently confirms the default `$JS.API` scope; it
does not infer remote domain/import prefixes from a publish subject or a separate
Admin. Applications must use the stream's own certified scope. This is a snapshot
check, not a server-side configuration lock: the operator must keep topology,
account routing and configuration stable for the duration of the batch. An active
or configured cross-domain leaf is refused even if its current routes appear safe.

After an attempted commit, a status-only HMSG (including 504) or a generic JSON
error without a JetStream-specific `err_code` cannot confirm rejection. JSON 408
remains uncertain even if it contains an `err_code`. Such errors preserve code,
description, batch ID and cause with `isOutcomeUnknown() === true`. Validated
JetStream client rejections with a specific code, such as `400/10071`, remain
definite; infrastructure `5xx` remains uncertain. If the commit write never began,
the outcome flag remains `false`.

Run `make integration-jetstream-atomic-routes` for the 2.15.2 → 2.10.26/2.12.6
leaf checks, including a stable mapping that previously bypassed STREAM.NAMES.
The standalone matrix separately checks matching account mappings and replaces
real successful final PubAcks with HMSG 504 and JSON 408, independently confirming
the stored messages and exactly one commit.
