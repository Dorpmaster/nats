<?php

declare(strict_types=1);

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketException;
use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PongMessage;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\ByteStream\getStderr;
use function Amp\Socket\socketConnector;

require_once __DIR__ . '/../vendor/autoload.php';

$logHandler = new StreamHandler(getStderr());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());

$logger = new Logger('worker');
$logger->pushHandler($logHandler);

$socketContext = (new ConnectContext())
    ->withConnectTimeout(1);

$socketConnector = socketConnector();

try {
    $logger->debug('Connecting to server');

    $socket = $socketConnector->connect('nats:4222', $socketContext);

    $logger->info('Connected to ' . $socket->getRemoteAddress());
} catch (SocketException $exception) {

    $logger->error($exception->getMessage(), ['exception' => $exception]);

    exit(1);
}

$logger->info($socket->read());

$command = (string) (new ConnectMessage(new ConnectInfo(
    verbose: false,
    pedantic: false,
    tls_required: false,
    lang: 'php',
    version: '8.3',
)));

$logger->debug($command);

$socket->write($command);

while (null !== $string = $socket->read(null, 1024)) {
    $logger->info($string);
    if ($string === "PING\r\n") {
        $command = (string)(new PongMessage());
        $logger->debug($command);
        $socket->write($command);
        $logger->debug('Stopping');
        break;
    }
}

$socket->close();
