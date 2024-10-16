<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Functional\Client;

use Amp\SignalCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\AsyncTestCase;

final class ClientRequestTest extends AsyncTestCase
{
    public function testRequest(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {

            $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

            $connector = new RetrySocketConnector(new DnsSocketConnector());
            $config    = new ConnectionConfiguration('nats', 4222);

            $connection          = new Connection($connector, $config, $this->logger);
            $clientConfiguration = new ClientConfiguration();
            $eventDispatcher     = new EventDispatcher();

            $connectionInfo    = new ConnectInfo(false, false, false, 'php', '8.3');
            $storage           = new SubscriptionStorage();
            $messageDispatcher = new MessageDispatcher($connectionInfo, $storage, $this->logger);

            $client = new Client(
                configuration: $clientConfiguration,
                cancellation: $cancellation,
                connection: $connection,
                eventDispatcher: $eventDispatcher,
                messageDispatcher: $messageDispatcher,
                storage: $storage,
                logger: $this->logger,
            );

            $client->connect();

            $client->subscribe('test', static function (MsgMessageInterface&NatsProtocolMessageInterface $message): PubMessageInterface {
                return new PubMessage($message->getReplyTo(), $message->getPayload());
            });

            $result = $client->request(new PubMessage('test', '!!!!!!!!!'), 3);

            $client->disconnect();

            self::assertInstanceOf(MsgMessageInterface::class, $result);
            self::assertInstanceOf(NatsProtocolMessageInterface::class, $result);
            self::assertSame('!!!!!!!!!', $result->getPayload());
        });
    }
}
