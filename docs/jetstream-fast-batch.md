# Streaming JetStream publication

`JetStreamFastBatchPublisherInterface` is an optional publisher capability for
NATS 2.14.0+ (API level 4). It leaves the existing publisher interfaces intact.
Use fast batches for long streams of messages with bounded producer credit.
Messages become visible as the server persists them, before commit. Cancellation
stops future sends; it does not roll back stored messages. For all-or-nothing
publication use [atomic batches](jetstream-publish.md).

```php
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\FastBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Exception\FastBatchException;

$session = (new JetStreamPublisher($client))->openFastBatch(
    'ORDERS',
    ['orders.eu', 'orders.us'],
    new FastBatchOptions(initialFlow: 16, maxInflightWindows: 2),
    timeoutMs: 30_000,
);
try {
    foreach ($source as $message) {
        $session->publish($message->subject, $message->payload, $message->headers);
    }
    $result = $session->finish(); // empty EOB, not stored as a message
    // Alternatively: $session->commit('orders.eu', $lastPayload, $headers).
} catch (FastBatchException $error) {
    $result = $error->getResult();
    $confirmedThrough = $result->getLastAcknowledgedSequence();
    $handedToWriterThrough = $result->getLastSentSequence();
    $unknownRanges = $result->getUnknownRanges(); // inclusive decimal from/to
    $serverProblem = $result->getProblemSequence();
    $code = $error->getApiCode();
    $errCode = $error->getErrorCode();
    // Reconcile the confirmed prefix and unknown ranges before deciding recovery.
    // Repeating the session can duplicate messages already stored by the server.
} finally {
    $session->cancel(); // idempotent; returns the existing result after completion
}
```

Opening requires a nonempty list of concrete publish subjects, a valid stream
name and a positive integer timeout. It checks the current ready connection,
`FAST_BATCH_PUBLISH`, stream `allow_batched=true`, and the same diagnostic topology
and subject-routing guard as atomic publication. `allow_atomic` may be false.
Unsupported capabilities, missing diagnostic access and unconfirmed routes fail
before any data frame. Diagnostics require the system requests described in the
[atomic publication guide](jetstream-publish.md). The guard assumes routing
configuration stays stable during the operation; it rejects matching subject
mappings and unverified remote paths. Subjects must belong to the declared set.

The public session methods are `publish(subject, payload, headers)`,
`commit(subject, payload, headers)`, `finish()`, `cancel()`,
`awaitAcknowledged(sequence: null)`, `getProgress()` and `getResult()`. Headers accept
a `HeaderBugInterface` or an array, as in existing protocol APIs. Binary payloads
and ordered repeated headers are preserved. Each call freezes its frame before
waiting for credit. Reserved batch, expected-stream and required-API headers
cannot be supplied by the caller. `finish()` requires at least one user message;
`commit()` also supports a single-message batch. One session allows sequential
operations; independent sessions can run concurrently. `cancel()` may interrupt
an operation waiting for credit or a write.

## Credit and deadlines

A session owns one wildcard inbox created before its first frame. The first
noncommitting send waits for the initial control response. ACK sequence zero sets
the server window but does not confirm message one. Subsequent ACKs are cumulative;
late lower ACKs cannot reduce the confirmed sequence or change the current window.
The server can shrink or enlarge the window up to the requested `initialFlow`.
The producer waits when outstanding messages reach `window * maxInflightWindows`
or when adding the frame would exceed `maxBufferedBytes`.

Defaults are `initialFlow=16`, `maxInflightWindows=2` (maximum 3),
`maxBufferedBytes=2097152`, `maxMessageBytes=65536`, `ackTimeoutMs=250` and
`pingTimeoutMs=1000`. Byte limits include the complete HPUB command, headers and
payload. Configuration must fit one full requested window of maximum-size frames
(`initialFlow * maxMessageBytes <= maxBufferedBytes`). This prevents byte credit
from stopping production before the next server ACK threshold. Sent payloads are
not retained by the session: only bounded pending sizes and decimal sequences
remain. One caller-held message/frame can additionally exist while blocked.

A stalled ACK wait sends one bounded protocol ping with the last handed-off batch
sequence, without incrementing it. It then waits `pingTimeoutMs` for progress.
A ping replays the server's latest flow ACK; it does not force an ACK for an
incomplete window. `awaitAcknowledged()` is useful at a flow boundary; finalize
the batch to obtain confirmation of a residual partial window. Finalization and
error settlement wait for a final PubAck within the remaining session deadline.
They do not send further pings or extend the lifetime. The monotonic deadline
starts before admission checks; the default total lifetime is 30 seconds.

## Results and cleanup

Batch ID, last handed-off sequence, cumulative confirmed sequence, final count
and stream sequence are separate values. All counters use canonical decimal
strings with exact UInt64 range. The last handed-off sequence advances immediately
before socket write, so a failed write can leave that message uncertain.

`gap=fail` and per-message errors stop further data sends while keeping the inbox
until a final PubAck or the remaining deadline. Gap and error notices alone do not
confirm persistence. A valid final PubAck must match stream and batch, contain
valid exact count/sequence, and not contradict prior confirmations. A partial
final PubAck confirms only that prefix and produces `FastBatchException`, with
`isComplete()=false`. A lost error notice cannot turn that prefix into full success.
Server `code`, `err_code`, description and the problem index are preserved when
available; the original transport cause remains in the exception chain.

`getUnknownRanges()` identifies attempted user messages whose persistence is not
confirmed. An explicit rejection or a trustworthy final prefix can remove this
uncertainty. If all user messages were cumulatively ACKed but the commit reply is
lost, ranges may be empty while `isOutcomeUnknown()` is true: final batch
termination was not confirmed. Inspect both fields and `getFinalAck()`.

Cancellation, timeout and connection replacement complete once, withdraw queued
unattempted frames and release the inbox, deadline and pending waiters. Attempted
frames, pings and commit are never replayed after reconnect. Late responses cannot
change the immutable result. A new session needs a new admission check and inbox;
ordinary client publication retains its existing retry behavior.

## Admission and terminal guards

`FastBatchSession` has a private constructor. Obtain sessions through
`JetStreamPublisher::openFastBatch()` or `FastBatchSession::open()`; both use the
same admission path, including server capability, stream flag and routing checks.
A session cannot be constructed with a caller-supplied connection ID or deadline.

The absolute monotonic deadline is checked synchronously at operation entry and
again immediately before each data, commit, EOB or ping write attempt. An event
loop timer remains responsible for waking suspended waits, but a producer that
occupies PHP past the deadline cannot start another write before timers run.
Closing the channel cancels every queued frame, including control frames. Stopping
outbound work after a server error is separate from closing the inbox for final replies.

Deduplication can produce a valid final `seq=0` even when `count` is positive.
`count` counts accepted batch entries, including duplicates; `seq` is the server's
reported stream sequence and is preserved exactly. A fully deduplicated batch
may succeed without adding any stored records. Identity, JSON numeric types,
UInt64 bounds and consistency with prior cumulative confirmations are still
validated. No artificial stream sequence is substituted.

On `err` or `gap`, the session withdraws every unattempted outbound frame,
including queued EOB and ping. Withdrawal is not a transport failure and cannot
close the inbox or finalize the result. `finish()` and `awaitAcknowledged()` keep
waiting for the final PubAck under the original deadline. If it arrives, its
confirmed prefix replaces the uncertainty of that prefix; the original server
code, error code and problem index remain available. If it is lost, only prior
cumulative ACKs count as confirmations. A rejected message is excluded from
unknown ranges; preceding messages without an ACK or final confirmation remain
unknown. Cancellation and connection loss still terminate settlement immediately.
