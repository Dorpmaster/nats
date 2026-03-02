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
