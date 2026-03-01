# JetStream Admin (MVP)

This module implements JetStream control-plane operations over `$JS.API.*`.

Scope:

- stream management (`STREAM.CREATE`, `STREAM.INFO`, `STREAM.DELETE`)
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

## Error Handling

`JetStreamControlPlaneTransport` throws `JetStreamApiException` when:

- JetStream API response contains `error` object
- request payload cannot be encoded to JSON
- response payload cannot be decoded from JSON

`JetStreamApiException` includes:

- `getApiCode(): int`
- `getDescription(): string`
