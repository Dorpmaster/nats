<?php

declare(strict_types=1);

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketException;
use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PongMessage;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Revolt\EventLoop;
use function Amp\ByteStream\getStderr;
use function Amp\delay;
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

$counter = 0;
EventLoop::queue(static function () use ($socket, $logger, &$counter): void {
    while (null !== $chunk = $socket->read()) {
        $logger->debug($chunk);
        if(str_contains($chunk, NatsMessageType::PING->value)) {
            $socket->write((string)(new PongMessage()));
            $counter++;
        }
    }

    $socket->close();
});

while ($counter < 3) {
    $logger->debug('Slipping');
    delay(3);
}