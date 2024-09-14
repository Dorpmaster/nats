<?php

declare(strict_types=1);

use Amp\DeferredFuture;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\NullCancellation;
use Amp\Parser\Parser;
use Amp\Socket\ConnectContext;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Amp\Socket\SocketException;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PongMessage;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\ByteStream\getStderr;
use function Amp\delay;
use function Amp\Socket\socketConnector;

require_once __DIR__ . '/../vendor/autoload.php';

$logHandler = new StreamHandler(getStderr());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());

$logger = new Logger('worker');
$logger->pushHandler($logHandler);

$source = function (): \Generator {
    yield 'INFO {"server_id":"NABGL';
    yield 'API2AJEVYIPG54HIEUXZ5O7PEM5ZV5RFAJAECKG6T4BUPMWXLHY","server_name":"us-south';
    yield '-nats-demo","version":"2.10.20","proto":1,"git_commit":"7140387","go":"go1.22.6",';
    yield '"host":"0.0.0.0","port":4222,"headers":true,"tls_available":true,"max_payload":1048576,';
    yield '"jetstream":true,"client_id":450394,"client_ip":"94.67.65.87","nonce":"FP8SuWGPDUB73U4",';
    yield '"xkey":"XBERSHAJDGJM3KTAXZEFG7FNJ45ABPXF5IS2OHOKS3FHXADSY65XEQIV"}'."\r\n";
};

$analyzer = function (\Closure $callback): \Generator
{
    while (true) {
        $type = yield ' ';

        $payload = match (NatsMessageType::tryFrom($type)) {
            NatsMessageType::INFO => new \Dorpmaster\Nats\Protocol\InfoMessage(yield NatsProtocolMessageInterface::DELIMITER),
            default => throw new RuntimeException('Unknown message type'),
        };

        $callback($payload);
    }
};

$parser = new Parser($analyzer($logger->info(...)));

foreach ($source() as $chunk) {
    $parser->push($chunk);
}


//
//$logger->info('Starting...');
//$cancellation = new \Amp\SignalCancellation([SIGTERM, SIGINT, SIGHUP]);
//
//$connector = new RetrySocketConnector(new DnsSocketConnector());
//$config = new \Dorpmaster\Nats\Connection\ConnectionConfiguration('nats', 4222);
//
//$connection = new \Dorpmaster\Nats\Connection\Connection(
//    $connector,
//    $config,
//    $logger,
//);
//
//try {
//    $connection->open();
//} catch (Throwable $exception) {
//    $logger->error('Could not open the connection', ['exception' => $exception]);
//
//    exit(1);
//}
//
//try {
//    while (null !== $message = $connection->receive($cancellation)) {
//        $logger->info('Received: ' . $message);
////        delay(1, true, $cancellation);
//    }
//} catch (\Amp\CancelledException $exception) {
//    $logger->warning($exception->getMessage());
//}
//
//$connection->close();
//
//exit(0);