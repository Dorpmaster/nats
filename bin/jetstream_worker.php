<?php

declare(strict_types=1);

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\SignalCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SlowConsumerPolicy as CoreSlowConsumerPolicy;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamDrainTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\SlowConsumerPolicy as JetStreamSlowConsumerPolicy;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

use function Amp\ByteStream\getStderr;

require_once __DIR__ . '/../vendor/autoload.php';

/** @return list<string> */
function envCsv(string $name, string $default = ''): array
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        $value = $default;
    }

    if (trim($value) === '') {
        return [];
    }

    $parts = array_filter(array_map(static fn (string $item): string => trim($item), explode(',', $value)), static fn (string $item): bool => $item !== '');

    return array_values($parts);
}

function envString(string $name, string $default): string
{
    $value = getenv($name);

    return ($value === false || $value === '') ? $default : $value;
}

function envInt(string $name, int $default): int
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return $default;
    }

    return (int) $value;
}

function envNullableInt(string $name, int|null $default): int|null
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    $normalized = strtolower(trim($value));
    if ($normalized === 'null' || $normalized === 'none' || $normalized === 'infinite' || $normalized === '-1') {
        return null;
    }

    if (!is_numeric($value)) {
        return $default;
    }

    return (int) $value;
}

function envFloat(string $name, float $default): float
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return $default;
    }

    return (float) $value;
}

function envBool(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function createLoggerFromEnv(string $channel = 'jetstream-worker'): Logger
{
    $handler = new StreamHandler(getStderr());
    $handler->pushProcessor(new PsrLogMessageProcessor());
    $handler->setFormatter(new ConsoleFormatter());

    $levelName = strtoupper(envString('NATS_LOG_LEVEL', 'info'));
    try {
        $handler->setLevel(Level::fromName($levelName));
    } catch (ValueError) {
        $handler->setLevel(Level::Info);
    }

    $logger = new Logger($channel);
    $logger->pushHandler($handler);

    return $logger;
}

/**
 * @param list<string> $servers
 *
 * @return list<ServerAddress>
 */
function parseServerAddresses(array $servers, int $defaultPort): array
{
    $parsed = [];

    foreach ($servers as $server) {
        [$host, $port] = array_pad(explode(':', $server, 2), 2, null);
        $host          = trim((string) $host);
        if ($host === '') {
            continue;
        }

        $resolvedPort = $defaultPort;
        if ($port !== null && $port !== '' && is_numeric($port)) {
            $resolvedPort = (int) $port;
        }

        $key           = sprintf('%s:%d', $host, $resolvedPort);
        $parsed[$key]  = new ServerAddress($host, $resolvedPort);
    }

    return array_values($parsed);
}

$logger       = createLoggerFromEnv();
$cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

$defaultHost = envString('NATS_HOST', 'nats');
$defaultPort = envInt('NATS_PORT', 4222);

$host             = envString('JS_HOST', $defaultHost);
$port             = envInt('JS_PORT', $defaultPort);
$connectTimeoutMs = envInt('NATS_CONNECT_TIMEOUT_MS', 1000);

$streamName      = envString('JS_STREAM', 'ORDERS');
$subjects        = envCsv('JS_SUBJECTS', 'orders.*');
$consumerName    = envString('JS_CONSUMER', 'C1');
$filterSubject   = envString('JS_FILTER_SUBJECT', 'orders.created');
$publishSubject  = envString('JS_PUBLISH_SUBJECT', $filterSubject);
$publishCount    = envInt('JS_PUBLISH_COUNT', 10);
$publishPrefix   = envString('JS_PUBLISH_PREFIX', 'hello-');
$consumeMax      = envInt('JS_CONSUME_MAX', 10);
$consumeBatch    = envInt('JS_CONSUME_BATCH', 10);
$consumeExpires  = envInt('JS_CONSUME_EXPIRES_MS', 1000);
$consumeNoWait   = envBool('JS_CONSUME_NO_WAIT', false);
$maxInFlightMsgs = envInt('JS_CONSUME_MAX_IN_FLIGHT_MESSAGES', 100);
$maxInFlightByte = envInt('JS_CONSUME_MAX_IN_FLIGHT_BYTES', 5_000_000);
$slowPolicyRaw   = strtolower(envString('JS_SLOW_CONSUMER_POLICY', 'error'));

$slowPolicy = $slowPolicyRaw === JetStreamSlowConsumerPolicy::DROP_NEW->value
    ? JetStreamSlowConsumerPolicy::DROP_NEW
    : JetStreamSlowConsumerPolicy::ERROR;

$reconnectServersRaw = envCsv('NATS_RECONNECT_SERVERS');
$seedServers         = parseServerAddresses($reconnectServersRaw, $port);
$seedServers[]       = new ServerAddress($host, $port);
$seedServers         = parseServerAddresses(
    array_map(static fn (ServerAddress $server): string => sprintf('%s:%d', $server->getHost(), $server->getPort()), $seedServers),
    $port,
);

$connector = new RetrySocketConnector(new DnsSocketConnector());
$config    = new ConnectionConfiguration(
    host: $host,
    port: $port,
    connectTimeout: $connectTimeoutMs / 1000,
);

$connection = new Connection($connector, $config, $logger);
$clientConfiguration = new ClientConfiguration(
    reconnectEnabled: envBool('NATS_RECONNECT_ENABLED', true),
    maxReconnectAttempts: envNullableInt('NATS_RECONNECT_ATTEMPTS', 10),
    reconnectBackoffInitialMs: envInt('NATS_RECONNECT_BACKOFF_INITIAL_MS', 50),
    reconnectBackoffMaxMs: envInt('NATS_RECONNECT_BACKOFF_MAX_MS', 1000),
    reconnectBackoffMultiplier: envFloat('NATS_RECONNECT_BACKOFF_MULTIPLIER', 2.0),
    reconnectJitterFraction: envFloat('NATS_RECONNECT_JITTER', 0.2),
    servers: $seedServers,
);
$eventDispatcher = new EventDispatcher();
$storage         = new SubscriptionStorage();
$messageDispatcher = new MessageDispatcher(
    connectInfo: new ConnectInfo(false, false, false, 'php', PHP_VERSION),
    storage: $storage,
    logger: $logger,
    maxPendingMessagesPerSubscription: 1000,
    slowConsumerPolicy: CoreSlowConsumerPolicy::ERROR,
    maxPendingBytesPerSubscription: 2_000_000,
);

$client = new Client(
    configuration: $clientConfiguration,
    cancellation: $cancellation,
    connection: $connection,
    eventDispatcher: $eventDispatcher,
    messageDispatcher: $messageDispatcher,
    storage: $storage,
    logger: $logger,
);

$transport = new JetStreamControlPlaneTransport($client, logger: $logger);
$admin     = new JetStreamAdmin($transport, $logger);
$publisher = new JetStreamPublisher($client, $logger);
$factory   = new JetStreamPullConsumerFactory($transport, logger: $logger);

$stopRequested = false;
$cancellation->subscribe(static function () use (&$stopRequested, $logger): void {
    $stopRequested = true;
    $logger->info('Termination signal received for JetStream worker');
});

$handle = null;

$logger->info('Starting JetStream worker', [
    'host' => $host,
    'port' => $port,
    'stream' => $streamName,
    'consumer' => $consumerName,
]);

try {
    $client->connect();

    try {
        $admin->getStreamInfo($streamName);
        $logger->info('JetStream stream already exists', ['stream' => $streamName]);
    } catch (JetStreamApiException) {
        $admin->createStream(new StreamConfig($streamName, $subjects));
        $logger->info('JetStream stream created', [
            'stream' => $streamName,
            'subjects' => count($subjects),
        ]);
    }

    $admin->createOrUpdateConsumer(
        $streamName,
        new ConsumerConfig(
            durableName: $consumerName,
            filterSubject: $filterSubject,
        ),
    );
    $logger->info('JetStream consumer ready', [
        'stream' => $streamName,
        'consumer' => $consumerName,
        'filter_subject' => $filterSubject,
    ]);

    for ($i = 1; $i <= $publishCount; $i++) {
        $payload = $publishPrefix . $i;
        $ack     = $publisher->publish(
            $publishSubject,
            $payload,
            PublishOptions::create(msgId: sprintf('%s-%d', $consumerName, $i)),
        );

        $logger->info('JetStream message published', [
            'subject' => $publishSubject,
            'bytes' => strlen($payload),
            'stream' => $ack->getStream(),
            'seq' => $ack->getSeq(),
            'duplicate' => $ack->isDuplicate(),
        ]);
    }

    $pull = $factory->create($streamName, $consumerName);
    $handle = $pull->consume(new PullConsumeOptions(
        batch: $consumeBatch,
        expiresMs: $consumeExpires,
        noWait: $consumeNoWait,
        maxInFlightMessages: $maxInFlightMsgs,
        maxInFlightBytes: $maxInFlightByte,
        policy: $slowPolicy,
    ));
    $acker = $handle->getAcker();

    $received = 0;
    while (!$stopRequested && ($consumeMax <= 0 || $received < $consumeMax)) {
        $message = $handle->next(2_000);
        if ($message === null) {
            if ($stopRequested) {
                break;
            }

            continue;
        }

        $received++;
        $logger->info('JetStream message consumed', [
            'subject' => $message->getSubject(),
            'bytes' => $message->getSizeBytes(),
            'delivery_count' => $message->getDeliveryCount(),
            'received_total' => $received,
        ]);

        $acker->ack($message);
    }
} catch (Throwable $exception) {
    $logger->error('JetStream worker failed', ['exception' => $exception]);

    exit(1);
} finally {
    if ($handle !== null) {
        if ($stopRequested) {
            try {
                $handle->drain(2_000);
            } catch (JetStreamDrainTimeoutException $exception) {
                $logger->warning('JetStream drain timed out', ['exception' => $exception]);
            } finally {
                $handle->stop();
            }
        } else {
            $handle->stop();
        }
    }

    try {
        $client->disconnect();
    } catch (Throwable $exception) {
        $logger->warning('Disconnect failed during JetStream shutdown', ['exception' => $exception]);
    }
}

$logger->info('JetStream worker stopped');
exit(0);
