<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\DeferredFuture;
use Amp\CancelledException;
use Amp\SignalCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use PHPUnit\Framework\TestCase;

final class ClientIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function setUpBeforeClass(): void
    {
        NatsServerHarness::waitUntilReady();
    }

    public function testConnectAndClose(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $client = $this->createClient();
            $subject = sprintf('it.lifecycle.%s', bin2hex(random_bytes(6)));

            try {
                // Act
                $client->connect();
                $client->disconnect();
                $client->disconnect();
                $client->connect();
                $sid = $client->subscribe($subject, static function (MsgMessageInterface&NatsProtocolMessageInterface $message): PubMessageInterface {
                    return new PubMessage((string) $message->getReplyTo(), 'ok');
                });
                $response = $client->request(new PubMessage($subject, 'ping'), 1);
                $client->unsubscribe($sid);

                // Assert
                self::assertSame('ok', $response->getPayload());
            } finally {
                $client->disconnect();
            }
        });
    }

    public function testPublishSubscribeRoundtrip(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $client   = $this->createClient();
            $subject  = sprintf('it.roundtrip.%s', bin2hex(random_bytes(6)));
            $payload  = 'hello';
            $deferred = new DeferredFuture();

            try {
                // Act
                $client->connect();
                $sid = $client->subscribe($subject, static function (NatsProtocolMessageInterface $message) use ($deferred): null {
                    if (!$deferred->isComplete()) {
                        $deferred->complete($message);
                    }

                    return null;
                });
                $client->publish(new PubMessage($subject, $payload));
                $message = $deferred->getFuture()->await(new TimeoutCancellation(1));
                $client->unsubscribe($sid);

                // Assert
                self::assertInstanceOf(MsgMessageInterface::class, $message);
                self::assertSame($subject, $message->getSubject());
                self::assertSame($payload, $message->getPayload());
                self::assertNull($message->getReplyTo());
            } finally {
                $client->disconnect();
            }
        });
    }

    public function testRequestReply(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $client  = $this->createClient();
            $subject = sprintf('rpc.ping.%s', bin2hex(random_bytes(6)));

            try {
                // Act
                $client->connect();
                $sid = $client->subscribe($subject, static function (MsgMessageInterface&NatsProtocolMessageInterface $message): PubMessageInterface {
                    return new PubMessage((string) $message->getReplyTo(), 'pong');
                });
                $response = $client->request(new PubMessage($subject, 'ping'), 1);
                $client->unsubscribe($sid);

                // Assert
                self::assertSame('pong', $response->getPayload());
            } finally {
                $client->disconnect();
            }
        });
    }

    public function testUnsubscribeStopsDelivery(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $client     = $this->createClient();
            $subject    = sprintf('it.unsub.%s', bin2hex(random_bytes(6)));
            $deferred   = new DeferredFuture();
            $deliveries = 0;

            // Act
            $client->connect();
            $sid = $client->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$deliveries, $deferred): null {
                $deliveries++;
                if (!$deferred->isComplete()) {
                    $deferred->complete($message);
                }

                return null;
            });
            $client->unsubscribe($sid);
            $client->publish(new PubMessage($subject, 'msg1'));

            // Assert
            try {
                $deferred->getFuture()->await(new TimeoutCancellation(0.2));
                self::fail('Unexpected message delivered after unsubscribe');
            } catch (CancelledException $exception) {
                self::assertInstanceOf(TimeoutException::class, $exception->getPrevious());
                self::assertSame(0, $deliveries);
            } finally {
                $client->disconnect();
            }
        });
    }

    public function testDrainClosesClientAndRejectsNewPublish(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $client   = $this->createClient();
            $subject  = sprintf('it.drain.%s', bin2hex(random_bytes(6)));
            $deferred = new DeferredFuture();

            try {
                $client->connect();
                $sid = $client->subscribe($subject, static function (NatsProtocolMessageInterface $message) use ($deferred): null {
                    if (!$deferred->isComplete()) {
                        $deferred->complete($message->getPayload());
                    }

                    return null;
                });
                $client->publish(new PubMessage($subject, 'before-drain'));
                $payload = $deferred->getFuture()->await(new TimeoutCancellation(1));
                $client->unsubscribe($sid);

                // Act
                $client->drain();

                // Assert
                self::assertSame('before-drain', $payload);
                try {
                    $client->publish(new PubMessage($subject, 'after-drain'));
                    self::fail('Expected publish to fail when client is closed after drain');
                } catch (ConnectionException $exception) {
                    self::assertStringContainsString('Could not publish while client state is CLOSED', $exception->getMessage());
                }
            } finally {
                $client->disconnect();
            }
        });
    }

    private function createClient(): Client
    {
        $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

        $connector = new RetrySocketConnector(new DnsSocketConnector());
        $config    = new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port());

        $connection          = new Connection($connector, $config, $this->logger);
        $clientConfiguration = new ClientConfiguration();
        $eventDispatcher     = new EventDispatcher();

        $connectionInfo    = new ConnectInfo(false, false, false, 'php', PHP_VERSION);
        $storage           = new SubscriptionStorage();
        $messageDispatcher = new MessageDispatcher($connectionInfo, $storage, $this->logger);

        return new Client(
            configuration: $clientConfiguration,
            cancellation: $cancellation,
            connection: $connection,
            eventDispatcher: $eventDispatcher,
            messageDispatcher: $messageDispatcher,
            storage: $storage,
            logger: $this->logger,
        );
    }
}
