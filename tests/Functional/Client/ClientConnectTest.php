<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Functional\Client;

use Amp\DeferredFuture;
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
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\AsyncTestCase;

final class ClientConnectTest extends AsyncTestCase
{
    public function testWaitForConnected(): void
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

            $deferred = new DeferredFuture();
            $client->subscribe('test', static function (NatsProtocolMessageInterface $message) use ($deferred): null {
                $deferred->complete($message);

                return null;
            });

            $client->publish(new PubMessage('test', '!!!!!!!!!'));
            $result = $deferred->getFuture()->await();

            $client->disconnect();

            self::assertInstanceOf(MsgMessageInterface::class, $result);
            self::assertInstanceOf(NatsProtocolMessageInterface::class, $result);
            self::assertSame('!!!!!!!!!', $result->getPayload());
        });
    }
}
