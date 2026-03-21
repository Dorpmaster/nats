<?php

declare(strict_types=1);

use Amp\Cancellation;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\SignalCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SlowConsumerPolicy;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Revolt\EventLoop;

use function Amp\ByteStream\getStderr;

require_once __DIR__ . '/../vendor/autoload.php';

/** @return list<string> */
function envCsv(string $name): array
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
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

function createLoggerFromEnv(string $channel = 'worker'): Logger
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

$logger       = createLoggerFromEnv('core-worker');
$cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

$host             = envString('NATS_HOST', 'nats');
$port             = envInt('NATS_PORT', 4222);
$connectTimeoutMs = envInt('NATS_CONNECT_TIMEOUT_MS', 1000);
$subject          = envString('NATS_SUBJECT', 'demo.jobs');
$replyPayload     = envString('NATS_REPLY_PAYLOAD', 'ok');
$publishEveryMs   = envInt('NATS_PUBLISH_EVERY_MS', 0);
$publishPayload   = envString('NATS_PUBLISH_PAYLOAD', 'demo-message');

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

$connection          = new Connection($connector, $config, $logger);
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

$connectionInfo = new ConnectInfo(false, false, false, 'php', PHP_VERSION);
$messageDispatcher = new MessageDispatcher(
    connectInfo: $connectionInfo,
    storage: $storage,
    logger: $logger,
    maxPendingMessagesPerSubscription: 1000,
    slowConsumerPolicy: SlowConsumerPolicy::ERROR,
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

$logger->info('Starting core worker', [
    'host' => $host,
    'port' => $port,
    'subject' => $subject,
]);

$publisherWatcher = null;
$subscriptionId   = null;
$publishedCount   = 0;

$cancellation->subscribe(static function () use ($logger): void {
    $logger->info('Termination signal received');
});

try {
    $client->connect();

    $subscriptionId = $client->subscribe($subject, static function ($message) use ($client, $logger, $replyPayload): null {
        $subjectValue = method_exists($message, 'getSubject') ? (string) $message->getSubject() : 'unknown';
        $bytes        = method_exists($message, 'getPayloadSize') ? (int) $message->getPayloadSize() : 0;
        $replyTo      = method_exists($message, 'getReplyTo') ? $message->getReplyTo() : null;

        $logger->info('Core worker message received', [
            'subject' => $subjectValue,
            'bytes' => $bytes,
            'has_reply_to' => $replyTo !== null && $replyTo !== '',
        ]);

        if (is_string($replyTo) && $replyTo !== '') {
            $client->publish(new PubMessage($replyTo, $replyPayload));
        }

        return null;
    });

    if ($publishEveryMs > 0) {
        $publishInterval = $publishEveryMs / 1000;
        $publisherWatcher = EventLoop::repeat($publishInterval, static function () use ($client, $subject, $publishPayload, $logger, &$publishedCount): void {
            $publishedCount++;
            $payload = sprintf('%s-%d', $publishPayload, $publishedCount);
            $client->publish(new PubMessage($subject, $payload));
            $logger->debug('Core worker published demo message', [
                'subject' => $subject,
                'bytes' => strlen($payload),
            ]);
        });

        $logger->info('Periodic publish enabled', [
            'interval_ms' => $publishEveryMs,
            'subject' => $subject,
        ]);
    }

    $client->waitForTermination();
} catch (Throwable $exception) {
    $logger->error('Core worker failed', [
        'exception' => $exception,
    ]);

    exit(1);
} finally {
    if ($publisherWatcher !== null) {
        EventLoop::cancel($publisherWatcher);
    }

    if (is_string($subscriptionId) && $subscriptionId !== '') {
        try {
            $client->unsubscribe($subscriptionId);
        } catch (Throwable $exception) {
            $logger->warning('Failed to unsubscribe during shutdown', ['exception' => $exception]);
        }
    }

    try {
        $client->disconnect();
    } catch (Throwable $exception) {
        $logger->warning('Disconnect failed during shutdown', ['exception' => $exception]);
    }
}

$logger->info('Core worker stopped');
exit(0);
