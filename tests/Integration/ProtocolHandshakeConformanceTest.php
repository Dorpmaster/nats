<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

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
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\FakeNatsProtocolServer;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class ProtocolHandshakeConformanceTest extends TestCase
{
    use AsyncTestTools;

    public function testSubscribeBeforeReadyIsBufferedUntilPong(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $client = $this->createClient($server);

            try {
                $client->connect();
                $client->subscribe('it.handshake.sub', static fn (): null => null);

                $server->awaitSessionCount(1);
                $server->awaitCommandCount(0, 2);
                self::assertSame(['CONNECT', 'PING'], $server->commandNames(0));

                $server->releasePong(0);
                $server->awaitCommandCount(0, 3);

                self::assertSame(['CONNECT', 'PING', 'SUB'], array_slice($server->commandNames(0), 0, 3));
                self::assertFalse($server->authorizationViolationSent(0));
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testPublishBeforeReadyIsBufferedUntilPong(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $client = $this->createClient($server);

            try {
                $client->connect();
                $client->publish(new PubMessage('it.handshake.pub', 'payload'));

                $server->awaitSessionCount(1);
                $server->awaitCommandCount(0, 2);
                self::assertSame(['CONNECT', 'PING'], $server->commandNames(0));

                $server->releasePong(0);
                $server->awaitCommandCount(0, 3);

                self::assertSame(['CONNECT', 'PING', 'PUB'], array_slice($server->commandNames(0), 0, 3));
                self::assertFalse($server->authorizationViolationSent(0));
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testRequestPathBuffersInboxSubAndRequestPublishUntilPong(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $client = $this->createClient($server);

            try {
                $client->connect();
                $future = async(fn(): MsgMessageInterface => $client->request(new PubMessage('it.handshake.rpc', 'payload'), 1.0));

                $server->awaitSessionCount(1);
                $server->awaitCommandCount(0, 2);
                self::assertSame(['CONNECT', 'PING'], $server->commandNames(0));

                $server->releasePong(0);
                $server->awaitCommandCount(0, 4);

                $commandNames = array_slice($server->commandNames(0), 0, 4);
                self::assertSame('CONNECT', $commandNames[0]);
                self::assertSame('PING', $commandNames[1]);
                self::assertSame('SUB', $commandNames[2]);
                self::assertContains($commandNames[3], ['PUB', 'HPUB']);

                $server->replyToLatestRequest(0, 'response');
                $response = $future->await();

                self::assertSame('response', $response->getPayload());
                self::assertFalse($server->authorizationViolationSent(0));
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testReconnectAlsoReappliesHandshakeBarrier(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $client = $this->createClient($server, new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 5,
                reconnectBackoffInitialMs: 1,
                reconnectBackoffMaxMs: 1,
                reconnectJitterFraction: 0.0,
                bufferWhileReconnecting: true,
            ));

            try {
                $client->connect();
                $client->subscribe('it.handshake.persisted', static fn (): null => null);

                $server->awaitSessionCount(1);
                $server->awaitCommandCount(0, 2);
                $server->releasePong(0);
                $server->awaitCommandCount(0, 3);

                $server->closeSession(0);
                $server->awaitSessionCount(2);
                $server->awaitCommandCount(1, 2);

                $client->subscribe('it.handshake.buffered', static fn (): null => null);
                $client->publish(new PubMessage('it.handshake.buffered', 'after-reconnect'));

                self::assertSame(['CONNECT', 'PING'], $server->commandNames(1));

                $server->releasePong(1);
                $server->awaitCommandCount(1, 5);

                $commandNames = $server->commandNames(1);
                self::assertSame(['CONNECT', 'PING'], array_slice($commandNames, 0, 2));
                self::assertNotContains('PUB', array_slice($commandNames, 0, 2));
                self::assertNotContains('HPUB', array_slice($commandNames, 0, 2));
                self::assertFalse($server->authorizationViolationSent(1));
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    public function testHandshakeCompletesWithoutAuthorizationViolation(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $client = $this->createClient($server);

            try {
                $client->connect();
                $client->publish(new PubMessage('it.handshake.ok', 'payload'));

                $server->awaitSessionCount(1);
                $server->awaitCommandCount(0, 2);
                $server->releasePong(0);
                $server->awaitCommandCount(0, 3);

                self::assertFalse($server->anyAuthorizationViolationSent());
                self::assertSame(['CONNECT', 'PING', 'PUB'], array_slice($server->commandNames(0), 0, 3));
            } finally {
                $client->disconnect();
                $server->stop();
            }
        });
    }

    private function createClient(FakeNatsProtocolServer $server, ClientConfiguration|null $clientConfiguration = null): Client
    {
        $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);
        $connector    = new RetrySocketConnector(new DnsSocketConnector());
        $connection   = new Connection(
            $connector,
            new ConnectionConfiguration($server->host(), $server->port()),
            $this->logger,
        );
        $storage      = new SubscriptionStorage();

        return new Client(
            configuration: $clientConfiguration ?? new ClientConfiguration(),
            cancellation: $cancellation,
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(
                new ConnectInfo(false, false, false, 'php', PHP_VERSION),
                $storage,
                $this->logger,
            ),
            storage: $storage,
            logger: $this->logger,
        );
    }
}
