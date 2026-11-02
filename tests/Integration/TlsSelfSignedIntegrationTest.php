<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
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
use Dorpmaster\Nats\Tests\Support\CapturedProtocolLogs;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class TlsSelfSignedIntegrationTest extends TestCase
{
    use AsyncTestTools;

    /** @var list<array<string, mixed>> */
    private array $observations = [];
    private CapturedProtocolLogs $logs;

    public static function certificates(): iterable
    {
        yield 'self-signed denied by default' => ['self', false, true, 'localhost', false];
        yield 'self-signed explicitly allowed' => ['self', true, true, 'localhost', true];
        yield 'self-signed wrong name denied' => ['self', true, true, 'wrong.example', false];
        yield 'self-signed expired denied' => ['expired', true, true, 'localhost', false];
        yield 'trusted CA default' => ['trusted', false, true, 'localhost', true];
        yield 'trusted CA with flag' => ['trusted', true, true, 'localhost', true];
        yield 'untrusted CA leaf denied' => ['untrusted', true, true, 'localhost', false];
        yield 'explicit verification disabled' => ['self', false, false, 'wrong.example', true];
        yield 'explicit verification disabled with flag' => ['self', true, false, 'wrong.example', true];
        yield 'mTLS default' => ['mtls', false, true, 'localhost', true];
        yield 'mTLS with flag' => ['mtls', true, true, 'localhost', true];
        yield 'weak key remains denied' => ['weak', true, true, 'localhost', false];
    }

    #[DataProvider('certificates')]
    public function testCertificatePolicy(string $host, bool $allow, bool $verify, string $name, bool $success): void
    {
        $this->requireMatrix();
        $this->setTimeout(15);
        $this->runAsyncTest(function () use ($host, $allow, $verify, $name, $success): void {
            $tls        = $this->tls($allow, $verify, $name, $host === 'mtls');
            $connection = $this->connection($host, $tls);
            $client     = $this->client($connection);
            try {
                try {
                    $client->connect();
                    self::assertTrue($success, 'Certificate should have been rejected');
                    $this->roundtrip($client);
                } catch (ConnectionException $error) {
                    if ($success) {
                        throw $error;
                    }
                    self::assertStringStartsWith('TLS handshake failed:', $error->getMessage());
                    self::assertTrue($connection->isClosed(), 'Failed transport must be detached');
                }
                self::assertCount(1, $this->observations);
                $this->assertOptions($this->observations[0], $allow, $verify, $name, $host === 'mtls');
            } finally {
                $client->disconnect();
                $this->forceTick();
                $this->logs->assertSafe(['synthetic-t6-client-passphrase', 'synthetic-t6-payload']);
            }
        });
    }

    public function testFailedHandshakeThenFreshConnectionAndRepeatedConnect(): void
    {
        $this->requireMatrix();
        $this->setTimeout(15);
        $this->runAsyncTest(function (): void {
            $connection = $this->connection('self', $this->tls(true));
            $connection->useServer(new ServerAddress('self', 4222, true, 'wrong.example'));
            try {
                $connection->open(new TimeoutCancellation(2));
                self::fail('Wrong name must fail');
            } catch (ConnectionException $error) {
                self::assertStringStartsWith('TLS handshake failed:', $error->getMessage());
                self::assertTrue($connection->isClosed());
            }
            $client = $this->client($connection, new ClientConfiguration(servers: [new ServerAddress('self', 4222, true, 'localhost')]));
            try {
                $client->connect();
                $this->roundtrip($client);
                $client->disconnect();
                $this->forceTick();
                $client->connect();
                $this->roundtrip($client);
                self::assertCount(3, $this->observations);
                self::assertSame(['wrong.example', 'localhost', 'localhost'], array_column($this->observations, 'peer_name'));
                foreach ($this->observations as $index => $options) {
                    $this->assertOptions($options, true, true, $index === 0 ? 'wrong.example' : 'localhost', false);
                }
            } finally {
                $client->disconnect();
                $this->forceTick();
                $this->logs->assertSafe(['synthetic-t6-payload']);
            }
        });
    }

    public function testFailoverReappliesFlagAndSelectedServerNameAndReplaysSubscriptions(): void
    {
        $this->requireMatrix();
        $this->setTimeout(25);
        $this->runAsyncTest(function (): void {
            $connection = $this->connection('self', $this->tls(true, true, 'wrong.example'));
            $client     = $this->client($connection, new ClientConfiguration(
                reconnectEnabled: true,
                maxReconnectAttempts: 8,
                reconnectBackoffInitialMs: 20,
                reconnectBackoffMaxMs: 100,
                reconnectJitterFraction: 0.0,
                servers: [new ServerAddress('self', 4222, true, 'localhost'), new ServerAddress('self2', 4222, true, 'localhost')],
                pingEnabled: true,
                pingIntervalMs: 100,
                pingTimeoutMs: 200,
            ));
            $subject    = 'matrix.failover.' . bin2hex(random_bytes(6));
            try {
                $client->connect();
                $client->subscribe($subject, static fn (NatsProtocolMessageInterface $message): PubMessage => new PubMessage((string) $message->getReplyTo(), $message->getPayload()));
                self::assertSame('before', $client->request(new PubMessage($subject, 'before'), 2)->getPayload());
                NatsServerHarness::stop();
                $deadline = hrtime(true) + 10_000_000_000;
                while ($client->getCurrentServer()?->getHost() !== 'self2' || $client->getState() !== ClientState::CONNECTED) {
                    self::assertLessThan($deadline, hrtime(true), 'Failover deadline');
                    delay(0.02);
                }
                self::assertSame('after', $client->request(new PubMessage($subject, 'after'), 2)->getPayload());
                self::assertSame('next', $client->request(new PubMessage($subject, 'next'), 2)->getPayload());
                self::assertGreaterThanOrEqual(2, count($this->observations));
                foreach ($this->observations as $options) {
                    $this->assertOptions($options, true, true, 'localhost', false);
                }
            } finally {
                $client->disconnect();
                NatsServerHarness::start();
                NatsServerHarness::waitUntilReady();
                $this->forceTick();
                $this->logs->assertSafe(['synthetic-t6-client-passphrase']);
            }
        });
    }

    private function requireMatrix(): void
    {
        if (getenv('NATS_TLS_MATRIX') !== '1') {
            self::markTestSkipped('Run make integration-tls-self-signed for the isolated certificate matrix.');
        }
        $this->logs = new CapturedProtocolLogs();
    }

    private function tls(bool $allow, bool $verify = true, string $name = 'localhost', bool $mtls = false): TlsConfiguration
    {
        $directory = dirname(__DIR__) . '/Support/tls/self-signed/';
        return new TlsConfiguration(
            enabled: true,
            verifyPeer: $verify,
            caFile: $directory . 'ca.pem',
            clientCertFile: $mtls ? $directory . 'client.pem' : null,
            clientKeyFile: $mtls ? $directory . 'client-key.pem' : null,
            clientKeyPassphrase: $mtls ? 'synthetic-t6-client-passphrase' : null,
            serverName: $name,
            allowSelfSigned: $allow,
            minVersion: 'TLSv1.2',
            alpnProtocols: ['nats'],
        );
    }

    private function connection(string $host, TlsConfiguration $tls): Connection
    {
        $connector = self::createStub(SocketConnector::class);
        $connector->method('connect')->willReturnCallback(function (string $uri, ConnectContext|null $context = null, Cancellation|null $cancellation = null): Socket {
            $real = (new DnsSocketConnector())->connect($uri, $context, $cancellation);
            self::assertInstanceOf(ResourceStream::class, $real);
            $socket = self::createMockForIntersectionOfInterfaces([Socket::class, ResourceStream::class]);
            foreach (['read', 'write', 'end', 'close', 'isClosed', 'getResource'] as $method) {
                $socket->method($method)->willReturnCallback(static fn (...$arguments) => $real->$method(...$arguments));
            }
            $socket->expects(self::once())->method('setupTls')->willReturnCallback(function (Cancellation|null $cancellation = null) use ($real, $context): void {
                // Exactly the merge performed by ResourceSocket immediately before crypto.
                // Extra stream options must survive this AMPHP merge.
                stream_context_set_options($real->getResource(), $context->getTlsContext()->toStreamContextArray());
                $this->observations[] = stream_context_get_options($real->getResource())['ssl'];
                $real->setupTls($cancellation);
            });
            return $socket;
        });
        return new Connection($connector, new ConnectionConfiguration($host, 4222, tls: $tls), $this->logs);
    }

    private function client(Connection $connection, ClientConfiguration|null $config = null): Client
    {
        $storage = new SubscriptionStorage();
        return new Client(
            configuration: $config ?? new ClientConfiguration(),
            cancellation: new NullCancellation(),
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION), $storage, $this->logs),
            storage: $storage,
            logger: $this->logs,
        );
    }

    private function roundtrip(Client $client): void
    {
        $subject = 'matrix.roundtrip.' . bin2hex(random_bytes(6));
        $client->subscribe($subject, static fn (NatsProtocolMessageInterface $message): PubMessage => new PubMessage((string) $message->getReplyTo(), $message->getPayload()));
        $payload = "synthetic-t6-payload\r\n\0";
        self::assertSame($payload, $client->request(new PubMessage($subject, $payload), 2)->getPayload());
        self::assertSame('next', $client->request(new PubMessage($subject, 'next'), 2)->getPayload());
    }

    /** @param array<string, mixed> $ssl */
    private function assertOptions(array $ssl, bool $allow, bool $verify, string $name, bool $mtls): void
    {
        self::assertSame($allow, $ssl['allow_self_signed'] ?? false);
        self::assertSame(2, $ssl['security_level']);
        self::assertSame($verify, $ssl['verify_peer']);
        self::assertSame($verify, $ssl['verify_peer_name']);
        self::assertSame($name, $ssl['peer_name']);
        self::assertTrue($ssl['SNI_enabled']);
        self::assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, $ssl['crypto_method']);
        self::assertSame('nats', $ssl['alpn_protocols']);
        self::assertStringEndsWith('/self-signed/ca.pem', $ssl['cafile']);
        self::assertSame($mtls, isset($ssl['local_cert']));
        if ($mtls) {
            self::assertStringEndsWith('/self-signed/client.pem', $ssl['local_cert']);
            self::assertStringEndsWith('/self-signed/client-key.pem', $ssl['local_pk']);
            self::assertSame('synthetic-t6-client-passphrase', $ssl['passphrase']);
        }
    }
}
