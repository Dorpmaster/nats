# JetStream Pull Consumer (MVP + Continuous Loop v1)

`JetStreamPullConsumer` provides bounded pull fetch and explicit ack operations via a dedicated acker service.

## Setup

Create stream and durable pull consumer via JetStream admin:

```php
$admin->createStream(new StreamConfig('ORDERS', ['orders.*']));
$admin->createOrUpdateConsumer('ORDERS', new ConsumerConfig('C1', filterSubject: 'orders.created'));
```

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

Fetch request uses:

- `batch`
- `expires` (ms -> ns)
- `no_wait`
- optional `max_bytes`
- optional `idle_heartbeat` (ms -> ns)

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

- `noWait=true` on empty consumer returns empty fetch result (`receivedCount=0`).
- `expiresMs` bounds fetch duration; method returns collected messages on timeout.

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
