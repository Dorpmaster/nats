<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Amp\ByteStream\ResourceStream;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;
use Dorpmaster\Nats\Tests\Support\CapturedProtocolLogs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionTlsOptionsTest extends TestCase
{
    private const string INFO = 'INFO {"server_id":"id","server_name":"nats","version":"1","go":"go","host":"localhost","port":4222,"headers":true,"max_payload":1024,"proto":1}' . "\r\n";
    public static function options(): iterable
    {
        yield 'verified default' => [false, true];
        yield 'verified self-signed' => [true, true];
        yield 'explicit verification disabled' => [true, false];
    }

    #[DataProvider('options')]
    public function testEffectiveStreamOptionsBeforeHandshake(bool $allow, bool $verify): void
    {
        $resource = fopen('php://temp', 'w+');
        // An earlier owner must not accidentally leave permission enabled.
        stream_context_set_option($resource, 'ssl', 'allow_self_signed', true);
        $socket = self::createMockForIntersectionOfInterfaces([Socket::class, ResourceStream::class]);
        $socket->method('getResource')->willReturn($resource);
        $socket->method('read')->willReturnOnConsecutiveCalls(self::INFO, null);
        $socket->expects(self::once())->method('setupTls')->willReturnCallback(static function () use ($resource, $allow, $verify): void {
            $ssl = stream_context_get_options($resource)['ssl'];
            self::assertSame($allow, $ssl['allow_self_signed'] ?? false);
            self::assertSame((new ClientTlsContext('localhost'))->toStreamContextArray()['ssl']['security_level'], $ssl['security_level']);
            self::assertSame($verify, $ssl['verify_peer']);
            self::assertSame($verify, $ssl['verify_peer_name']);
            self::assertSame('selected.example', $ssl['peer_name']);
            self::assertTrue($ssl['SNI_enabled']);
            self::assertSame(ClientTlsContext::TLSv1_3, $ssl['crypto_method']);
            self::assertSame('/tmp/synthetic-ca.pem', $ssl['cafile']);
            self::assertSame('/tmp/synthetic-ca', $ssl['capath']);
            self::assertSame('/tmp/synthetic-client.pem', $ssl['local_cert']);
            self::assertSame('/tmp/synthetic-client.key', $ssl['local_pk']);
            self::assertSame('synthetic-t6-key-secret', $ssl['passphrase']);
            self::assertSame('nats,test', $ssl['alpn_protocols']);
        });
        $connector = self::createMock(SocketConnector::class);
        $connector->expects(self::once())->method('connect')->willReturnCallback(static function (string $uri, ConnectContext $context) use ($resource, $socket): Socket {
            self::assertSame('tcp://selected:4223', $uri);
            // Model the context ResourceSocket applies when setting up crypto.
            stream_context_set_options($resource, $context->getTlsContext()->toStreamContextArray());
            return $socket;
        });
        $logs       = new CapturedProtocolLogs();
        $connection = new Connection($connector, new ConnectionConfiguration('unused', 4222, tls: new TlsConfiguration(
            enabled: true,
            verifyPeer: $verify,
            caFile: '/tmp/synthetic-ca.pem',
            caPath: '/tmp/synthetic-ca',
            clientCertFile: '/tmp/synthetic-client.pem',
            clientKeyFile: '/tmp/synthetic-client.key',
            clientKeyPassphrase: 'synthetic-t6-key-secret',
            serverName: 'fallback.example',
            allowSelfSigned: $allow,
            minVersion: 'TLSv1.3',
            alpnProtocols: ['nats', 'test'],
        )), $logs);
        $connection->useServer(new ServerAddress('selected', 4223, serverName: 'selected.example'));
        try {
            $connection->open();
            $logs->assertSafe(['synthetic-t6-key-secret']);
        } finally {
            $connection->close();
            fclose($resource);
        }
    }

    public function testUnsupportedSocketFailsClearlyAndIsClosed(): void
    {
        $socket = self::createMock(Socket::class);
        $socket->expects(self::never())->method('setupTls');
        $socket->expects(self::never())->method('read');
        $socket->expects(self::once())->method('close');
        $connector = self::createStub(SocketConnector::class);
        $connector->method('connect')->willReturn($socket);
        $connection = new Connection($connector, new ConnectionConfiguration('localhost', 4222, tls: new TlsConfiguration(enabled: true, allowSelfSigned: true)));
        try {
            $connection->open();
            self::fail('Expected unsupported socket to be rejected');
        } catch (ConnectionException $error) {
            self::assertStringStartsWith('TLS handshake failed:', $error->getMessage());
            self::assertStringContainsString('PHP stream resource', $error->getMessage());
            self::assertTrue($connection->isClosed());
        }
    }

    public function testUnavailableStreamResourceIsRejectedBeforeReadingAndClosed(): void
    {
        $socket = self::createMockForIntersectionOfInterfaces([Socket::class, ResourceStream::class]);
        $socket->method('getResource')->willReturn(null);
        $socket->expects(self::never())->method('read');
        $socket->expects(self::never())->method('setupTls');
        $socket->expects(self::once())->method('close');
        $connector = self::createStub(SocketConnector::class);
        $connector->method('connect')->willReturn($socket);
        $connection = new Connection($connector, new ConnectionConfiguration('localhost', 4222, tls: new TlsConfiguration(enabled: true, allowSelfSigned: true)));
        try {
            $connection->open();
            self::fail('Expected unavailable stream to be rejected');
        } catch (ConnectionException $error) {
            self::assertSame('TLS handshake failed: TLS configuration requires an open PHP stream resource', $error->getMessage());
            self::assertTrue($connection->isClosed());
        }
    }

    public function testFailedHandshakeClosesSocketAndNextAttemptUsesFreshTransport(): void
    {
        $first = self::createMock(Socket::class);
        $first->method('read')->willReturn(self::INFO);
        $first->expects(self::once())->method('setupTls')->willThrowException(new RuntimeException('synthetic-t6-handshake-secret'));
        // Even a broken close must not replace the original handshake error.
        $first->expects(self::once())->method('close')->willThrowException(new RuntimeException('synthetic-t6-cleanup-secret'));
        $second = self::createMock(Socket::class);
        $second->method('read')->willReturnOnConsecutiveCalls(self::INFO, null);
        $second->expects(self::once())->method('setupTls');
        $connector = self::createMock(SocketConnector::class);
        $connector->expects(self::exactly(2))->method('connect')->willReturnOnConsecutiveCalls($first, $second);
        $logs       = new CapturedProtocolLogs();
        $connection = new Connection($connector, new ConnectionConfiguration('localhost', 4222, tls: new TlsConfiguration(enabled: true)), $logs);
        try {
            $connection->open();
            self::fail('Expected TLS failure');
        } catch (ConnectionException $error) {
            self::assertSame('TLS handshake failed: synthetic-t6-handshake-secret', $error->getMessage());
            self::assertTrue($connection->isClosed());
        }
        $connection->open();
        $connection->close();
        $logs->assertSafe(['synthetic-t6-handshake-secret', 'synthetic-t6-cleanup-secret']);
    }
}
