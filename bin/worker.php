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
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\ByteStream\getStderr;

require_once __DIR__ . '/../vendor/autoload.php';

$logHandler = new StreamHandler(getStderr());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());
//$logHandler->setLevel(\Monolog\Level::Info);

$logger = new Logger('worker');
$logger->pushHandler($logHandler);

$logger->info('Starting...');
$cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

$connector = new RetrySocketConnector(new DnsSocketConnector());
$config = new ConnectionConfiguration('nats', 4222);

$connection = new Connection($connector, $config, $logger);
$clientConfiguration = new ClientConfiguration();
$eventDispatcher = new EventDispatcher();

$connectionInfo = new ConnectInfo(false, false, false, 'php', '8.3');
$messageDispatcher = new MessageDispatcher($connectionInfo, $logger);

$client = new Client(
    $clientConfiguration,
    $cancellation,
    $connection,
    $eventDispatcher,
    $messageDispatcher,
    $logger
);

try {
    $client->connect();
} catch (Throwable $exception) {
    $logger->error('Could not open the connection', ['exception' => $exception]);

    exit(1);
}

$client->waitForTermination();

$client->disconnect();

exit(0);