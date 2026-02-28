<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\DeferredFuture;
use Amp\SignalCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\NatsClusterHarness;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class ClientClusterFailoverIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function setUpBeforeClass(): void
    {
        self::requireClusterMode();
        NatsClusterHarness::waitNodeAcceptsInfo('n1');
        NatsClusterHarness::waitNodeAcceptsInfo('n2');
        NatsClusterHarness::waitNodeAcceptsInfo('n3');
    }

    public function testDiscoveryAddsClusterNodes(): void
    {
        self::requireClusterMode();
        $this->setTimeout(30);
        $this->runAsyncTest(function (): void {
            // Arrange
            $client = $this->createClient(
                new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 20,
                    reconnectBackoffInitialMs: 50,
                    reconnectBackoffMaxMs: 500,
                    reconnectBackoffMultiplier: 2.0,
                    reconnectJitterFraction: 0.0,
                    pingEnabled: true,
                    pingIntervalMs: 100,
                    pingTimeoutMs: 200,
                    pingReconnectOnTimeout: true,
                    servers: [new ServerAddress('n1', 4222)],
                ),
            );

            try {
                // Act
                $client->connect();
                $this->awaitKnownServersCount($client, 3, 5.0);

                // Assert
                self::assertGreaterThanOrEqual(3, count($client->getKnownServers()));
            } finally {
                $client->disconnect();
            }
        });
    }

    public function testFailoverToAnotherNodeWhenCurrentStops(): void
    {
        self::requireClusterMode();
        $this->setTimeout(40);
        $this->runAsyncTest(function (): void {
            // Arrange
            $subject     = sprintf('it.cluster.failover.%s', bin2hex(random_bytes(6)));
            $received    = new DeferredFuture();
            $subscriber  = $this->createClient(new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 20,
                reconnectBackoffInitialMs: 50,
                reconnectBackoffMaxMs: 500,
                reconnectBackoffMultiplier: 2.0,
                reconnectJitterFraction: 0.0,
                servers: [new ServerAddress('n1', 4222), new ServerAddress('n2', 4222)],
            ));
            $publisher   = $this->createClient(new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 20,
                reconnectBackoffInitialMs: 50,
                reconnectBackoffMaxMs: 500,
                reconnectBackoffMultiplier: 2.0,
                reconnectJitterFraction: 0.0,
                servers: [new ServerAddress('n2', 4222)],
            ));
            $stoppedHost = 'n1';

            try {
                $subscriber->connect();
                $publisher->connect();
                self::assertSame('n1', $subscriber->getCurrentServer()?->getHost());
                $subscriber->subscribe($subject, static function (NatsProtocolMessageInterface $message) use ($received): null {
                    if (!$received->isComplete()) {
                        $received->complete($message->getPayload());
                    }

                    return null;
                });

                // Act
                NatsClusterHarness::stopNode($stoppedHost);
                $this->awaitFailover($subscriber, $stoppedHost, 10.0);
                $publishDeadline = microtime(true) + 5.0;
                while (true) {
                    try {
                        $publisher->publish(new PubMessage($subject, 'after-failover'));

                        break;
                    } catch (\Throwable $exception) {
                        if (microtime(true) >= $publishDeadline) {
                            throw $exception;
                        }

                        delay(0.05);
                    }
                }

                $payload = $received->getFuture()->await(new TimeoutCancellation(2));

                // Assert
                self::assertSame('after-failover', $payload);
                self::assertNotNull($subscriber->getCurrentServer());
                self::assertNotSame($stoppedHost, $subscriber->getCurrentServer()?->getHost());
            } finally {
                NatsClusterHarness::startNode($stoppedHost ?? 'n1');
                NatsClusterHarness::waitNodeReady($stoppedHost ?? 'n1');
                $publisher->disconnect();
                $subscriber->disconnect();
            }
        });
    }

    public function testWriteBufferSurvivesFailoverWhenEnabled(): void
    {
        self::requireClusterMode();
        $this->setTimeout(50);
        $this->runAsyncTest(function (): void {
            // Arrange
            $subject     = sprintf('it.cluster.buffer.%s', bin2hex(random_bytes(6)));
            $expected    = 5;
            $received    = [];
            $stoppedHost = 'n1';
            $publisher   = $this->createClient(new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 20,
                reconnectBackoffInitialMs: 50,
                reconnectBackoffMaxMs: 500,
                reconnectBackoffMultiplier: 2.0,
                reconnectJitterFraction: 0.0,
                servers: [new ServerAddress('n1', 4222), new ServerAddress('n2', 4222)],
                bufferWhileReconnecting: true,
                maxWriteBufferMessages: 100,
                maxWriteBufferBytes: 100_000,
            ));
            $subscriber  = $this->createClient(new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 20,
                reconnectBackoffInitialMs: 50,
                reconnectBackoffMaxMs: 500,
                reconnectBackoffMultiplier: 2.0,
                reconnectJitterFraction: 0.0,
                servers: [new ServerAddress('n2', 4222)],
            ));

            try {
                $publisher->connect();
                $subscriber->connect();
                $subscriber->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$received): null {
                    $received[] = $message->getPayload();

                    return null;
                });

                // Act
                self::assertSame('n1', $publisher->getCurrentServer()?->getHost());
                NatsClusterHarness::stopNode($stoppedHost);
                $this->awaitClientState($publisher, ClientState::RECONNECTING, 3.0);

                for ($i = 0; $i < $expected; $i++) {
                    $publisher->publish(new PubMessage($subject, 'msg-' . $i));
                }

                $this->awaitFailover($publisher, $stoppedHost, 10.0);
                $this->awaitPayloadCount($received, $expected, 12.0);

                // Assert
                self::assertCount($expected, $received);
            } finally {
                NatsClusterHarness::startNode($stoppedHost ?? 'n1');
                NatsClusterHarness::waitNodeReady($stoppedHost ?? 'n1');
                $subscriber->disconnect();
                $publisher->disconnect();
            }
        });
    }

    private function reconnectClusterConfig(): ClientConfiguration
    {
        return new ClientConfiguration(
            reconnectEnabled: true,
            maxReconnectAttempts: 20,
            reconnectBackoffInitialMs: 50,
            reconnectBackoffMaxMs: 500,
            reconnectBackoffMultiplier: 2.0,
            reconnectJitterFraction: 0.0,
            pingEnabled: true,
            pingIntervalMs: 100,
            pingTimeoutMs: 200,
            pingReconnectOnTimeout: true,
            servers: [new ServerAddress('n1', 4222), new ServerAddress('n2', 4222), new ServerAddress('n3', 4222)],
        );
    }

    private function createClient(ClientConfiguration $clientConfiguration): Client
    {
        $cancellation            = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);
        $connector               = new RetrySocketConnector(new DnsSocketConnector());
        $connectionConfiguration = new ConnectionConfiguration('n1', 4222);
        $connection              = new Connection($connector, $connectionConfiguration, $this->logger);
        $eventDispatcher         = new EventDispatcher();
        $connectionInfo          = new ConnectInfo(false, false, false, 'php', PHP_VERSION);
        $storage                 = new SubscriptionStorage();
        $messageDispatcher       = new MessageDispatcher($connectionInfo, $storage, $this->logger);

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

    private function awaitKnownServersCount(Client $client, int $expectedCount, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (count($client->getKnownServers()) < $expectedCount && microtime(true) < $deadline) {
            delay(0.05);
        }

        self::assertGreaterThanOrEqual($expectedCount, count($client->getKnownServers()));
    }

    /** @param list<string> $received */
    private function awaitPayloadCount(array &$received, int $count, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (count($received) < $count && microtime(true) < $deadline) {
            delay(0.05);
        }

        self::assertGreaterThanOrEqual($count, count($received));
    }

    private function awaitClientState(Client $client, ClientState $state, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while ($client->getState() !== $state && microtime(true) < $deadline) {
            delay(0.05);
        }

        self::assertSame($state, $client->getState());
    }

    private function awaitFailover(Client $client, string $oldHost, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $currentHost = $client->getCurrentServer()?->getHost();
            if ($client->getState() === ClientState::CONNECTED && $currentHost !== null && $currentHost !== $oldHost) {
                return;
            }

            delay(0.05);
        }

        self::fail(sprintf(
            'Client did not fail over from host "%s" within %.1f seconds. Current state: %s, current host: %s',
            $oldHost,
            $timeoutSeconds,
            $client->getState()->value,
            $client->getCurrentServer()?->getHost() ?? 'null',
        ));
    }

    private static function requireClusterMode(): void
    {
        if ((string) (getenv('NATS_CLUSTER') ?: '0') !== '1') {
            self::markTestSkipped('Cluster integration is disabled. Run with NATS_CLUSTER=1.');
        }
    }
}
