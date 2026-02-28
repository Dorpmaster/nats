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
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use PHPUnit\Framework\TestCase;

final class ClientTlsIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function setUpBeforeClass(): void
    {
        self::requireTlsMode();
        NatsServerHarness::waitUntilReady();
    }

    public function testTlsConnectAndRoundtripWithPeerVerification(): void
    {
        self::requireTlsMode();

        $this->setTimeout(30);
        $this->runAsyncTest(function (): void {
            // Arrange
            $client   = $this->createClient(new TlsConfiguration(
                enabled: true,
                verifyPeer: true,
                caFile: self::caFile(),
                serverName: 'localhost',
            ));
            $subject  = sprintf('it.tls.roundtrip.%s', bin2hex(random_bytes(6)));
            $deferred = new DeferredFuture();

            try {
                $client->connect();
                $sid = $client->subscribe($subject, static function (NatsProtocolMessageInterface $message) use ($deferred): null {
                    if (!$deferred->isComplete()) {
                        $deferred->complete($message->getPayload());
                    }

                    return null;
                });

                // Act
                $client->publish(new PubMessage($subject, 'secure'));
                $payload = $deferred->getFuture()->await(new TimeoutCancellation(1));
                $client->unsubscribe($sid);

                // Assert
                self::assertSame('secure', $payload);
            } finally {
                $client->disconnect();
            }
        });
    }

    public function testTlsFailsWithWrongServerNameWhenPeerVerificationEnabled(): void
    {
        self::requireTlsMode();

        // Arrange
        $client = $this->createClient(new TlsConfiguration(
            enabled: true,
            verifyPeer: true,
            caFile: self::caFile(),
            serverName: 'wrong.example',
        ));

        // Assert
        self::expectException(ConnectionException::class);
        self::expectExceptionMessage('TLS handshake failed:');

        // Act
        $client->connect();
    }

    private function createClient(TlsConfiguration $tlsConfiguration): Client
    {
        $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

        $connector = new RetrySocketConnector(new DnsSocketConnector());
        $config    = new ConnectionConfiguration(
            host: NatsServerHarness::host(),
            port: NatsServerHarness::port(),
            tls: $tlsConfiguration,
        );

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

    private static function requireTlsMode(): void
    {
        if ((string) (getenv('NATS_TLS') ?: '0') !== '1') {
            self::markTestSkipped('TLS integration is disabled. Run with NATS_TLS=1.');
        }
    }

    private static function caFile(): string
    {
        return dirname(__DIR__) . '/Support/tls/ca.pem';
    }
}
