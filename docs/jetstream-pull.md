# JetStream Pull Consumer (MVP)

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

## noWait / expires Semantics

- `noWait=true` on empty consumer returns empty fetch result (`receivedCount=0`).
- `expiresMs` bounds fetch duration; method returns collected messages on timeout.

## Delivery Guarantees

- at-least-once semantics
- duplicates are possible
- acknowledgements are required for progress tracking
