<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\CancelledException;
use Amp\DeferredFuture;
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

use function Amp\delay;

final class ClientReconnectIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function setUpBeforeClass(): void
    {
        NatsServerHarness::waitUntilReady();
    }

    public function testReconnectAfterServerRestartResubscribes(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $client   = $this->createReconnectClient();
            $subject  = sprintf('it.reconnect.restart.%s', bin2hex(random_bytes(6)));
            $received = [];
            $deferred = new DeferredFuture();

            try {
                $client->connect();
                $sid = $client->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$received, $deferred): null {
                    $received[] = $message->getPayload();
                    if (count($received) >= 2 && !$deferred->isComplete()) {
                        $deferred->complete($received);
                    }

                    return null;
                });

                // Act
                $client->publish(new PubMessage($subject, 'msg1'));
                $this->awaitMessageCount($received, 1, 1.0);
                NatsServerHarness::restart();
                NatsServerHarness::waitUntilReady();
                $this->publishUntilDelivered($client, $subject, 'msg2', $received, 2, 3.0);
                $result = $deferred->getFuture()->await(new TimeoutCancellation(1));
                $client->unsubscribe($sid);

                // Assert
                self::assertSame(['msg1', 'msg2'], $result);
            } finally {
                $client->disconnect();
            }
        });
    }

    public function testReconnectAfterServerStopStart(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $client   = $this->createReconnectClient();
            $subject  = sprintf('it.reconnect.stopstart.%s', bin2hex(random_bytes(6)));
            $received = [];

            try {
                $client->connect();
                $sid = $client->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$received): null {
                    $received[] = $message->getPayload();

                    return null;
                });

                // Act
                NatsServerHarness::stop();
                NatsServerHarness::waitUntilDown();
                NatsServerHarness::start();
                NatsServerHarness::waitUntilReady();
                $this->publishUntilDelivered($client, $subject, 'after-restart', $received, 1, 3.0);
                $client->unsubscribe($sid);

                // Assert
                self::assertSame(['after-restart'], $received);
            } finally {
                $client->disconnect();
            }
        });
    }

    public function testRequestDuringDisconnectFailsPredictably(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function () {
            // Arrange
            $client  = $this->createReconnectClient();
            $subject = sprintf('it.reconnect.request.%s', bin2hex(random_bytes(6)));

            try {
                $client->connect();
                $sid = $client->subscribe($subject, static function (MsgMessageInterface&NatsProtocolMessageInterface $message): PubMessageInterface {
                    return new PubMessage((string) $message->getReplyTo(), 'pong');
                });
                NatsServerHarness::stop();
                NatsServerHarness::waitUntilDown();

                // Act + Assert
                try {
                    $client->request(new PubMessage($subject, 'ping'), 0.3);
                    self::fail('Expected request to fail while the server is down');
                } catch (CancelledException $exception) {
                    self::assertInstanceOf(TimeoutException::class, $exception->getPrevious());
                } catch (ConnectionException $exception) {
                    self::assertNotEmpty($exception->getMessage());
                }

                $client->unsubscribe($sid);
            } finally {
                NatsServerHarness::ensureUp();
                $client->disconnect();
            }
        });
    }

    private function createReconnectClient(): Client
    {
        $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

        $connector = new RetrySocketConnector(new DnsSocketConnector());
        $config    = new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port());

        $connection          = new Connection($connector, $config, $this->logger);
        $clientConfiguration = new ClientConfiguration(
            reconnectEnabled: true,
            maxReconnectAttempts: 20,
            reconnectBackoffInitialMs: 50,
            reconnectBackoffMaxMs: 500,
            reconnectBackoffMultiplier: 2.0,
            reconnectJitterFraction: 0.0,
        );
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

    /** @param list<string> $received */
    private function awaitMessageCount(array &$received, int $expectedCount, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (count($received) < $expectedCount && microtime(true) < $deadline) {
            delay(0.01);
        }

        self::assertGreaterThanOrEqual($expectedCount, count($received));
    }

    /** @param list<string> $received */
    private function publishUntilDelivered(
        Client $client,
        string $subject,
        string $payload,
        array &$received,
        int $expectedCount,
        float $timeoutSeconds,
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;
        while (count($received) < $expectedCount && microtime(true) < $deadline) {
            try {
                $client->publish(new PubMessage($subject, $payload));
            } catch (\Throwable) {
                // Connection may still be reconnecting; retry within bounded time window.
            }
            delay(0.05);
        }

        self::assertGreaterThanOrEqual($expectedCount, count($received));
    }
}
