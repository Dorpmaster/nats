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
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\NatsClusterHarness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

#[Group('integration-cluster-tls')]
final class ClientClusterTlsFailoverIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function setUpBeforeClass(): void
    {
        self::requireClusterTlsMode();
        NatsClusterHarness::waitNodeAcceptsInfoTls('n1');
        NatsClusterHarness::waitNodeAcceptsInfoTls('n2');
        NatsClusterHarness::waitNodeAcceptsInfoTls('n3');
    }

    public function testTlsClusterFailover(): void
    {
        self::requireClusterTlsMode();

        $this->setTimeout(45);
        $this->runAsyncTest(function (): void {
            // Arrange
            $subject     = sprintf('it.cluster.tls.failover.%s', bin2hex(random_bytes(6)));
            $received    = new DeferredFuture();
            $stoppedHost = 'n1';
            $subscriber  = $this->createClient(
                new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 20,
                    reconnectBackoffInitialMs: 50,
                    reconnectBackoffMaxMs: 500,
                    reconnectBackoffMultiplier: 2.0,
                    reconnectJitterFraction: 0.0,
                    servers: [
                        new ServerAddress('n1', 4222, true),
                        new ServerAddress('n2', 4222, true),
                    ],
                ),
                $this->tlsConfiguration(),
            );
            $publisher   = $this->createClient(
                new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 20,
                    reconnectBackoffInitialMs: 50,
                    reconnectBackoffMaxMs: 500,
                    reconnectBackoffMultiplier: 2.0,
                    reconnectJitterFraction: 0.0,
                    servers: [
                        new ServerAddress('n1', 4222, true),
                        new ServerAddress('n2', 4222, true),
                    ],
                ),
                $this->tlsConfiguration(),
            );

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
                $this->awaitFailover($subscriber, $stoppedHost, 12.0);
                $this->publishWithRetry($publisher, new PubMessage($subject, 'after-failover'), 6.0);
                $payload = $received->getFuture()->await(new TimeoutCancellation(3));

                // Assert
                self::assertSame('after-failover', $payload);
                self::assertNotSame($stoppedHost, $subscriber->getCurrentServer()?->getHost());
            } finally {
                NatsClusterHarness::startNode($stoppedHost);
                $publisher->disconnect();
                $subscriber->disconnect();
            }
        });
    }

    public function testTlsWriteBufferDuringFailover(): void
    {
        self::requireClusterTlsMode();

        $this->setTimeout(50);
        $this->runAsyncTest(function (): void {
            // Arrange
            $subject     = sprintf('it.cluster.tls.buffer.%s', bin2hex(random_bytes(6)));
            $expected    = 5;
            $received    = [];
            $stoppedHost = 'n1';
            $subscriber  = $this->createClient(
                new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 20,
                    reconnectBackoffInitialMs: 50,
                    reconnectBackoffMaxMs: 500,
                    reconnectBackoffMultiplier: 2.0,
                    reconnectJitterFraction: 0.0,
                    servers: [new ServerAddress('n2', 4222, true)],
                ),
                $this->tlsConfiguration(),
            );
            $publisher   = $this->createClient(
                new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 20,
                    reconnectBackoffInitialMs: 50,
                    reconnectBackoffMaxMs: 500,
                    reconnectBackoffMultiplier: 2.0,
                    reconnectJitterFraction: 0.0,
                    bufferWhileReconnecting: true,
                    maxWriteBufferMessages: 20,
                    maxWriteBufferBytes: 50_000,
                    servers: [
                        new ServerAddress('n1', 4222, true),
                        new ServerAddress('n2', 4222, true),
                    ],
                ),
                $this->tlsConfiguration(),
            );

            try {
                $subscriber->connect();
                $publisher->connect();
                self::assertSame('n1', $publisher->getCurrentServer()?->getHost());

                $subscriber->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$received): null {
                    $received[] = $message->getPayload();

                    return null;
                });

                // Act
                NatsClusterHarness::stopNode($stoppedHost);
                $this->awaitClientState($publisher, ClientState::RECONNECTING, 5.0);
                for ($i = 0; $i < $expected; $i++) {
                    $publisher->publish(new PubMessage($subject, 'msg-' . $i));
                }
                $this->awaitFailover($publisher, $stoppedHost, 12.0);
                $this->awaitPayloadCount($received, $expected, 12.0);

                // Assert
                self::assertCount($expected, $received);
            } finally {
                NatsClusterHarness::startNode($stoppedHost);
                $publisher->disconnect();
                $subscriber->disconnect();
            }
        });
    }

    public function testTlsVerifyPeerRejectsWrongCa(): void
    {
        self::requireClusterTlsMode();

        // Arrange
        $client = $this->createClient(
            new ClientConfiguration(
                reconnectEnabled: false,
                servers: [new ServerAddress('n1', 4222, true)],
            ),
            new TlsConfiguration(
                enabled: true,
                verifyPeer: true,
                caFile: dirname(__DIR__) . '/Support/tls/ca.pem',
                clientCertFile: self::clientCertFile(),
                clientKeyFile: self::clientKeyFile(),
                serverName: 'n1',
            ),
        );

        // Assert
        self::expectException(ConnectionException::class);
        self::expectExceptionMessage('TLS');

        // Act
        $client->connect();
    }

    private function createClient(ClientConfiguration $clientConfiguration, TlsConfiguration $tlsConfiguration): Client
    {
        $cancellation            = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);
        $connector               = new RetrySocketConnector(new DnsSocketConnector());
        $connectionConfiguration = new ConnectionConfiguration('n1', 4222, tls: $tlsConfiguration);
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

    private function tlsConfiguration(): TlsConfiguration
    {
        return new TlsConfiguration(
            enabled: true,
            verifyPeer: true,
            caFile: self::caFile(),
            clientCertFile: self::clientCertFile(),
            clientKeyFile: self::clientKeyFile(),
        );
    }

    private function publishWithRetry(Client $publisher, PubMessage $message, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            try {
                $publisher->publish($message);

                return;
            } catch (\Throwable $exception) {
                if (microtime(true) >= $deadline) {
                    throw $exception;
                }

                delay(0.05);
            }
        }
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
            'TLS client did not fail over from host "%s" within %.1f seconds. Current state: %s, current host: %s',
            $oldHost,
            $timeoutSeconds,
            $client->getState()->value,
            $client->getCurrentServer()?->getHost() ?? 'null',
        ));
    }

    private static function requireClusterTlsMode(): void
    {
        if ((string) (getenv('NATS_CLUSTER_TLS') ?: '0') !== '1') {
            self::markTestSkipped('Cluster TLS integration is disabled. Run with NATS_CLUSTER_TLS=1.');
        }
    }

    private static function caFile(): string
    {
        return dirname(__DIR__) . '/Support/tls/cluster/ca.pem';
    }

    private static function clientCertFile(): string
    {
        return dirname(__DIR__) . '/Support/tls/cluster/client.pem';
    }

    private static function clientKeyFile(): string
    {
        return dirname(__DIR__) . '/Support/tls/cluster/client-key.pem';
    }
}
