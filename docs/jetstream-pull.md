# JetStream Pull Consumer (MVP + Continuous Loop v1)

`JetStreamPullConsumer` provides bounded pull fetch and explicit ack operations via a dedicated acker service.

## Setup

Create stream and durable pull consumer via JetStream admin:

```php
$admin->createStream(new StreamConfig('ORDERS', ['orders.*']));
$admin->createOrUpdateConsumer('ORDERS', new ConsumerConfig('C1', filterSubject: 'orders.created'));
```

The factory and direct `JetStreamPullConsumer` constructor apply the same
[resource name validation](jetstream-admin.md#local-validation) as Admin.
Invalid stream or durable consumer names raise `InvalidArgumentException`
before an inbox subscription or pull request is created.

## Fetch

```php
$factory = new JetStreamPullConsumerFactory($transport);
$consumer = $factory->create('ORDERS', 'C1');

$result = $consumer->fetch(batch: 5, expiresMs: 2000, noWait: false);
$acker = $result->getAcker();

foreach ($result->messages() as $message) {
    // process payload
    $acker->ack($message);
}
```

Fetch subscribes to a unique inbox before publishing. Each call owns its completion
state and local deadline, including concurrent calls on the same consumer. The
subscription stays active until the batch or byte budget is fulfilled, the server
finishes the request, an error occurs, or the local deadline expires. Cleanup
cancels the timer and unsubscribes once; late callbacks cannot change the result.
A cleanup failure cannot replace the original fetch error.

Request parameters:

- `batch`, `no_wait`, and optional `max_bytes` are sent in both modes.
- With `noWait: false`, `expiresMs` is sent as `expires` in nanoseconds;
  optional `idleHeartbeatMs` is sent as `idle_heartbeat` in nanoseconds.
- With `noWait: true`, `expiresMs` is only a local response deadline.
  Neither `expires` nor `idle_heartbeat` is sent, even if a heartbeat interval
  was supplied. This avoids incompatible no-wait/expiry/heartbeat combinations.

## Message DTO + Ack API

`JetStreamMessage` is a DTO (`subject`, `payload`, `headers`, `replyTo`).
Ack actions are executed by `JetStreamMessageAckerInterface`:

- `ack($message)` -> `+ACK`
- `nak($message)` -> `-NAK`
- `nak($message, $delayMs)` -> `-NAK <delayNs>`
- `term($message)` -> `+TERM`
- `inProgress($message)` -> `+WPI`

## Continuous Consume Loop

`consume()` runs repeated pull-fetches and exposes a handle-driven API:

```php
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;

$handle = $consumer->consume(new PullConsumeOptions(
    batch: 10,
    expiresMs: 1000,
    noWait: false,
    maxInFlightMessages: 1000,
    maxInFlightBytes: 5_000_000,
));

$acker = $handle->getAcker();

while (($message = $handle->next(2000)) !== null) {
    // process
    $acker->ack($message);
}
```

Handle lifecycle:

- `stop()` stops new fetches and completes stream (`next()` returns `null` after queue is drained).
- `drain(timeout)` stops new fetches and waits for:
  - internal queue to become empty
  - `inFlightMessages` to become `0` (all delivered messages must be released via `ack|nak|term`)
  If timeout is reached, `JetStreamDrainTimeoutException` is raised.
- `getState()` returns `RUNNING|STOPPING|DRAINING|STOPPED`.

## Local Backpressure

Backpressure is local to consume handle and based on delivered-but-not-released messages:

- `maxInFlightMessages`
- `maxInFlightBytes`
- `policy`:
  - `ERROR`: `JetStreamSlowConsumerException` is raised.
  - `DROP_NEW`: extra messages are dropped from delivery to user code.

`DROP_NEW` does **not** auto-ack dropped messages. They remain unacknowledged and may be redelivered by JetStream.
Guard behavior:
- if header `Nats-Num-Delivered` is present and value is `> 5`, dropped message is auto-terminated (`+TERM`)
- if header is absent, guard is not applied

## Reconnect Behavior

- Continuous consume loop is reconnect-aware.
- Temporary `ConnectionException` / fetch timeout do not terminate loop while handle state is `RUNNING`.
- After reconnect, loop continues issuing pull requests.
- In-flight counters are not reset by reconnect; semantics remain at-least-once and redelivery is possible.

## noWait / expires Semantics

`no_wait` asks the server not to wait for new messages. The client still waits for
responses to its request. Available messages are returned in delivery order, with
the existing explicit ACK contract. An empty or partial batch returns as soon as
the server confirms completion; it does not wait out `expiresMs`.

For `noWait: true`, silence or partial data without confirmed completion raises
`JetStreamTimeoutException` at the local deadline. A full batch or fulfilled
`maxBytes` budget needs no additional terminal status. NATS counts subject, ACK
reply, header block and payload bytes against that budget. Parsed HMSG frames
retain their received body size through the optional `WireBodySizeMessageInterface`,
so normalization of header whitespace does not change completion at an exact limit.

For `noWait: false`, server expiry returns collected messages. The existing local
fallback remains `expiresMs + 200 ms` and returns collected messages if the server
has not sent a terminal response.

### Protocol statuses

The protocol parser exposes the first HMSG header line as optional `MessageStatus`
through `StatusMessageInterface`; regular headers remain separate. Status-only
frames with no ordinary headers or payload are supported. Existing HMSG
constructor arguments and message interfaces remain compatible.

- `100`: heartbeat; keep waiting and exclude it from the result.
- `404` / `408`: normal completion (no messages / request expiry).
- `409` with `Batch Completed` or `Message Size Exceeds MaxBytes`: normal completion.
- Other statuses: `JetStreamApiException` preserving server code and description,
  including `409 Consumer Deleted` and `503 No Responders`.

Service frames never become application messages. Legacy JSON error responses
remain supported; JSON `404` completes an empty/partial fetch and other JSON
errors retain their code and description.

## Delivery Guarantees

- at-least-once semantics
- duplicates are possible
- acknowledgements are required for progress tracking

## Logging

`JetStreamPullConsumer`, `JetStreamConsumeLoop`, and consume handle accept optional `Psr\Log\LoggerInterface`.
If logger is omitted, `NullLogger` is used.

Key events:
- `js.pull.fetch`
- `js.pull.fetch.result`
- `js.pull.fetch.empty`
- `js.consume.start`
- `js.consume.fetch.retry`
- `js.consume.slow_consumer`
- `js.consume.drain.start`
- `js.consume.drain.success`
- `js.consume.drain.timeout`
- `js.ack.send`
- `js.ack.missing_reply`
