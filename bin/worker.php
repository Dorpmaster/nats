<?php

declare(strict_types=1);

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Parser\Parser;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketException;
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
    ->withConnectTimeout(1)
;

$socketConnector = socketConnector();

try {
    $logger->debug('Connecting to server');

    $socket = $socketConnector->connect('nats:4222', $socketContext);

    $logger->info('Connected to ' . $socket->getRemoteAddress());
} catch (SocketException $exception) {

    $logger->error($exception->getMessage(), ['exception' => $exception]);

    exit(1);
}

$readTimeout = new \Amp\TimeoutCancellation(1);
while (null !== $string = $socket->read(null, 4)) {
    $logger->info($string);
}

$config = [
    'verbose' => false,
    'pedantic' => false,
    'tls_required' => false,
    'lang' => 'php',
    'version' => '1.0.0',
];
$command = 'CONNECT ' . json_encode($config) . "\r\n";
$socket->write($command);

$logger->info($socket->read());

$socket->close();