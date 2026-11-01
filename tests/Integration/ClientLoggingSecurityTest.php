<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\NullCancellation;
use Amp\Socket\DnsSocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\CapturedProtocolLogs;
use Dorpmaster\Nats\Tests\Support\FakeNatsProtocolServer;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class ClientLoggingSecurityTest extends TestCase
{
    use AsyncTestTools;

    private int $readyCount = 0;

    private const array CREDENTIALS = [
        'pass' => 'SYNTHETIC_NATS_PASSWORD_7',
        'user' => 'SYNTHETIC_NATS_USER_7',
        'auth_token' => 'SYNTHETIC_NATS_TOKEN_7',
        'jwt' => 'SYNTHETIC_NATS_JWT_7',
        'sig' => 'SYNTHETIC_NATS_SIGNATURE_7',
        'nkey' => 'SYNTHETIC_NATS_NKEY_7',
    ];

    public function testConnectSendsCredentialsButKeepsFormattedAndRawLogsSafe(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $logs   = new CapturedProtocolLogs();
            $client = $this->createClient($server, $logs);
            try {
                $client->connect();
                $this->completeServerHandshake($server, 0);
                $this->awaitReadyCount(1);
            } finally {
                $client->disconnect();
                $server->stop();
                $this->forceTick();
            }
            $logs->assertSafe(array_values(self::CREDENTIALS));
            self::assertStringContainsString('DEBUG', $logs->output());
            self::assertStringContainsString('CONNECT', $logs->output());
            self::assertStringContainsString('ready', $logs->output());
        });
    }

    public function testReconnectResendsCredentialsWithoutLoggingThem(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $logs   = new CapturedProtocolLogs();
            $client = $this->createClient($server, $logs, true);
            try {
                $client->connect();
                $this->completeServerHandshake($server, 0);
                $this->awaitReadyCount(1);
                $server->closeSession(0);
                $this->completeServerHandshake($server, 1);
                $this->awaitReadyCount(2);
            } finally {
                $client->disconnect();
                $server->stop();
                $this->forceTick();
            }
            self::assertStringContainsString('RECONNECTING', $logs->output());
            $logs->assertSafe(array_values(self::CREDENTIALS));
        });
    }

    public function testAuthorizationRejectionDoesNotLogServerEchoedCredentials(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $logs   = new CapturedProtocolLogs();
            $client = $this->createClient($server, $logs);
            try {
                $client->connect();
                $server->awaitSessionCount(1);
                $server->awaitCommandCount(0, 2);
                $this->assertWireCredentials($server, 0);
                $server->rejectAuthorization(0, implode(' ', self::CREDENTIALS));
                $this->awaitCondition(static fn (): bool => count(array_filter($logs->records(), static fn (array $record): bool => $record['level'] === 'error')) > 0);
            } finally {
                $client->disconnect();
                $server->stop();
                $this->forceTick();
            }
            $logs->assertSafe(array_values(self::CREDENTIALS));
            self::assertStringContainsString('ERROR', $logs->output());
            self::assertStringContainsString('authorization', $logs->output());
        });
    }

    public function testIncomingPayloadIsDeliveredWithoutAppearingInLogs(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $server = new FakeNatsProtocolServer();
            $logs   = new CapturedProtocolLogs();
            $client = $this->createClient($server, $logs);
            $marker = "SYNTHETIC_PRIVATE_PAYLOAD_7\r\n\0";
            try {
                $client->connect();
                $this->completeServerHandshake($server, 0);
                $this->awaitReadyCount(1);
                $response = async(fn () => $client->request(new PubMessage('audit.payload', 'request'), 2));
                $server->awaitCommandCount(0, 4);
                $server->replyToLatestRequest(0, $marker);
                self::assertSame($marker, $response->await()->getPayload());
            } finally {
                $client->disconnect();
                $server->stop();
                $this->forceTick();
            }
            $logs->assertSafe([...array_values(self::CREDENTIALS), $marker, 'SYNTHETIC_PRIVATE_PAYLOAD_7']);
        });
    }

    private function completeServerHandshake(FakeNatsProtocolServer $server, int $session): void
    {
        $server->awaitSessionCount($session + 1);
        $server->awaitCommandCount($session, 2);
        $this->assertWireCredentials($server, $session);
        $server->releasePong($session);
    }

    private function assertWireCredentials(FakeNatsProtocolServer $server, int $session): void
    {
        $command = $server->rawCommands($session)[0];
        self::assertStringStartsWith('CONNECT ', $command);
        $fields = json_decode(substr($command, 8), true, 512, JSON_THROW_ON_ERROR);
        foreach (self::CREDENTIALS as $field => $expected) {
            self::assertSame($expected, $fields[$field]);
        }
    }

    private function awaitReadyCount(int $count): void
    {
        $this->awaitCondition(fn (): bool => $this->readyCount >= $count);
    }

    private function awaitCondition(\Closure $condition): void
    {
        $deadline = hrtime(true) + 2_000_000_000;
        while (!$condition()) {
            if (hrtime(true) >= $deadline) {
                self::fail('Timed out waiting for a protocol log observation');
            }
            delay(0.005);
        }
    }

    private function createClient(FakeNatsProtocolServer $server, CapturedProtocolLogs $logs, bool $reconnect = false): Client
    {
        $info    = new ConnectInfo(false, false, false, 'php', PHP_VERSION, auth_token: self::CREDENTIALS['auth_token'], user: self::CREDENTIALS['user'], pass: self::CREDENTIALS['pass'], jwt: self::CREDENTIALS['jwt'], sig: self::CREDENTIALS['sig'], nkey: self::CREDENTIALS['nkey']);
        $storage = new SubscriptionStorage();
        $events  = new EventDispatcher();
        $events->subscribe('connectionStatusChanged', function (string $event, mixed $state): void {
            if ($state === ClientState::CONNECTED) {
                $this->readyCount++;
            }
        });
        return new Client(
            configuration: new ClientConfiguration(reconnectEnabled: $reconnect, maxReconnectAttempts: 2, reconnectBackoffInitialMs: 1, reconnectJitterFraction: 0),
            cancellation: new NullCancellation(),
            connection: new Connection(new DnsSocketConnector(), new ConnectionConfiguration($server->host(), $server->port()), $logs),
            eventDispatcher: $events,
            messageDispatcher: new MessageDispatcher($info, $storage, $logs),
            storage: $storage,
            logger: $logs,
        );
    }
}
