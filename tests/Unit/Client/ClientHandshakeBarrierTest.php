<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\CancelledException;
use Amp\NullCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PongMessage;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Protocol\SubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\ManualDelayStrategy;
use Dorpmaster\Nats\Tests\Support\ScriptedConnection;
use PHPUnit\Framework\TestCase;

final class ClientHandshakeBarrierTest extends TestCase
{
    use AsyncTestTools;

    private const string INFO_PAYLOAD = '{"server_id":"id","server_name":"nats","version":"1","go":"go","host":"127.0.0.1","port":4222,"headers":true,"max_payload":1024,"proto":1}';

    public function testSubscribeAndPublishAreBufferedUntilInitialHandshakeCompletes(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $receiveCalls = 0;
            $connection   = new ScriptedConnection(
                onReceive: static function () use (&$receiveCalls) {
                    return match ($receiveCalls++) {
                        0 => new InfoMessage(self::INFO_PAYLOAD),
                        1 => new PongMessage(),
                        default => throw new CancelledException(),
                    };
                },
            );

            $client = $this->createClient($connection);

            $client->connect();
            $sid = $client->subscribe('orders.created', static fn () => null);
            $client->publish(new PubMessage('orders.created', 'payload'));

            self::assertSame([], $connection->sentWire());

            $this->forceTick();
            $this->forceTick();

            self::assertSame([
                (string) new ConnectMessage($this->connectInfo()),
                (string) new PingMessage(),
                (string) new SubMessage('orders.created', $sid),
                (string) new PubMessage('orders.created', 'payload'),
            ], $connection->sentWire());
        });
    }

    public function testRequestBuffersInboxSubscriptionAndHpubUntilReady(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $receiveCalls = 0;
            $idHelper     = self::createStub(SubscriptionIdHelperInterface::class);
            $idHelper->method('generateId')->willReturnOnConsecutiveCalls('requestid', 'replysid');

            $connection = new ScriptedConnection(
                onReceive: static function () use (&$receiveCalls) {
                    return match ($receiveCalls++) {
                        0 => new InfoMessage(self::INFO_PAYLOAD),
                        1 => new PongMessage(),
                        2 => new MsgMessage('receiverrequestid', 'replysid', 'response'),
                        default => throw new CancelledException(),
                    };
                },
            );

            $client = $this->createClient($connection, subscriptionIdHelper: $idHelper);
            $client->connect();
            $response = $client->request(
                new HPubMessage('rpc.orders', 'payload', new HeaderBag(['Nats-Msg-Id' => '123'])),
                1,
            );
            $this->forceTick();

            self::assertSame('response', $response->getPayload());

            self::assertSame([
                (string) new ConnectMessage($this->connectInfo()),
                (string) new PingMessage(),
                (string) new SubMessage('receiverrequestid', 'replysid'),
                (string) new HPubMessage(
                    'rpc.orders',
                    'payload',
                    new HeaderBag(['Nats-Msg-Id' => '123']),
                    'receiverrequestid',
                ),
                'UNSUB replysid' . "\r\n",
            ], $connection->sentWire());
        });
    }

    public function testReconnectKeepsOutboundBufferedUntilReadyAgain(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $openCalls     = 0;
            $receiveCalls  = 0;
            $delayStrategy = new ManualDelayStrategy();

            $connection = new ScriptedConnection(
                onOpen: static function (ScriptedConnection $connection) use (&$openCalls): void {
                    $openCalls++;
                    if ($openCalls === 2) {
                        $connection->markClosed();
                        throw new \RuntimeException('reconnect attempt failed');
                    }

                    $connection->markOpen();
                },
                onReceive: static function (ScriptedConnection $connection) use (&$receiveCalls) {
                    return match ($receiveCalls++) {
                        0 => new InfoMessage(self::INFO_PAYLOAD),
                        1 => new PongMessage(),
                        2 => $connection->closeAndReturnNull(),
                        3 => new InfoMessage(self::INFO_PAYLOAD),
                        4 => new PongMessage(),
                        default => throw new CancelledException(),
                    };
                },
            );

            $client = $this->createClient(
                $connection,
                configuration: new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 2,
                    reconnectBackoffInitialMs: 1,
                    reconnectBackoffMaxMs: 1,
                    reconnectJitterFraction: 0.0,
                    bufferWhileReconnecting: true,
                ),
                delayStrategy: $delayStrategy,
            );

            $client->connect();
            $this->forceTick();
            $this->forceTick();
            $this->forceTick();

            $client->publish(new PubMessage('orders.replayed', 'after-reconnect'));
            self::assertSame([
                (string) new ConnectMessage($this->connectInfo()),
                (string) new PingMessage(),
            ], $connection->sentWire());

            $delayStrategy->releaseNext();
            $this->forceTick();
            $this->forceTick();
            $this->forceTick();

            self::assertSame([
                (string) new ConnectMessage($this->connectInfo()),
                (string) new PingMessage(),
                (string) new ConnectMessage($this->connectInfo()),
                (string) new PingMessage(),
                (string) new PubMessage('orders.replayed', 'after-reconnect'),
            ], $connection->sentWire());
        });
    }

    public function testReconnectReplaysSubscriptionsBeforeBufferedPublishes(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $openCalls     = 0;
            $receiveCalls  = 0;
            $delayStrategy = new ManualDelayStrategy();

            $connection = new ScriptedConnection(
                onOpen: static function (ScriptedConnection $connection) use (&$openCalls): void {
                    $openCalls++;
                    $connection->markOpen();
                },
                onReceive: static function (ScriptedConnection $connection) use (&$receiveCalls) {
                    return match ($receiveCalls++) {
                        0 => new InfoMessage(self::INFO_PAYLOAD),
                        1 => new PongMessage(),
                        2 => $connection->closeAndReturnNull(),
                        3 => new InfoMessage(self::INFO_PAYLOAD),
                        4 => new PongMessage(),
                        default => throw new CancelledException(),
                    };
                },
            );

            $client = $this->createClient(
                $connection,
                configuration: new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 1,
                    reconnectBackoffInitialMs: 1,
                    reconnectBackoffMaxMs: 1,
                    reconnectJitterFraction: 0.0,
                    bufferWhileReconnecting: true,
                ),
                delayStrategy: $delayStrategy,
            );

            $client->connect();
            $sid = $client->subscribe('orders.buffered', static fn () => null);
            $this->forceTick();
            $this->forceTick();
            $this->forceTick();

            $client->publish(new PubMessage('orders.buffered', 'after-reconnect'));

            $delayStrategy->releaseNext();
            $this->forceTick();
            $this->forceTick();
            $this->forceTick();
            $this->forceTick();

            $sent                  = $connection->sentWire();
            $reconnectConnectIndex = array_search(
                (string) new ConnectMessage($this->connectInfo()),
                array_slice($sent, 1),
                true,
            );
            self::assertNotFalse($reconnectConnectIndex);
            $reconnectConnectIndex++;

            $reconnectPingIndex = array_search(
                (string) new PingMessage(),
                array_slice($sent, $reconnectConnectIndex + 1),
                true,
            );
            self::assertNotFalse($reconnectPingIndex);
            $reconnectPingIndex += $reconnectConnectIndex + 1;

            $replayedSubIndex = array_search(
                (string) new SubMessage('orders.buffered', $sid),
                array_slice($sent, $reconnectPingIndex + 1),
                true,
            );
            self::assertNotFalse($replayedSubIndex);
            $replayedSubIndex += $reconnectPingIndex + 1;

            $bufferedPublishIndex = array_search(
                (string) new PubMessage('orders.buffered', 'after-reconnect'),
                array_slice($sent, $replayedSubIndex + 1),
                true,
            );
            self::assertNotFalse($bufferedPublishIndex);
        });
    }

    private function createClient(
        ConnectionInterface $connection,
        ClientConfiguration|null $configuration = null,
        SubscriptionIdHelperInterface|null $subscriptionIdHelper = null,
        DelayStrategyInterface|null $delayStrategy = null,
    ): Client {
        $storage = new SubscriptionStorage();

        return new Client(
            configuration: $configuration ?? new ClientConfiguration(),
            cancellation: new NullCancellation(),
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher($this->connectInfo(), $storage, $this->logger),
            storage: $storage,
            logger: $this->logger,
            delayStrategy: $delayStrategy,
            subscriptionIdHelper: $subscriptionIdHelper,
        );
    }

    private function connectInfo(): ConnectInfo
    {
        return new ConnectInfo(false, false, false, 'php', '8.3');
    }
}
