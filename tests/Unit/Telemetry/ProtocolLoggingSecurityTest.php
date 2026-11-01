<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Telemetry;

use Amp\NullCancellation;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\InboundDispatchScheduler;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\ErrMessage;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\CapturedProtocolLogs;
use Dorpmaster\Nats\Tests\Support\ScriptedConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProtocolLoggingSecurityTest extends TestCase
{
    use AsyncTestTools;

    private const string MARKER = 'SYNTHETIC_NATS_ERROR_PAYLOAD_7';
    private const string INFO   = '{"server_id":"test","server_name":"test","version":"1","go":"go","host":"127.0.0.1","port":4222,"headers":true,"max_payload":1024,"proto":1}';

    #[DataProvider('dispatcherCases')]
    public function testDispatcherBranchesDoNotExposeProtocolObjectsOrPayload(string $case): void
    {
        $logs       = new CapturedProtocolLogs();
        $storage    = new SubscriptionStorage();
        $dispatcher = new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, pass: self::MARKER), $storage, $logs);
        if ($case === 'wrong-response') {
            $storage->add('sid1', static fn (): object => (object) ['secret' => self::MARKER]);
        }
        $message = match ($case) {
            'info' => new InfoMessage(self::INFO),
            'error' => new ErrMessage('Authorization Violation ' . self::MARKER),
            default => new MsgMessage('audit.test', 'sid1', self::MARKER),
        };
        $response = $dispatcher->dispatch($message);
        if ($case === 'info') {
            self::assertInstanceOf(ConnectMessage::class, $response);
            self::assertStringContainsString(self::MARKER, (string) $response);
        } else {
            self::assertNull($response);
        }
        $logs->assertSafe([self::MARKER]);
    }

    public static function dispatcherCases(): iterable
    {
        foreach (['info', 'error', 'missing-handler', 'wrong-response'] as $case) {
            yield $case => [$case];
        }
    }

    public function testConnectionOpenFailurePreservesOriginalExceptionWithoutLoggingItsContents(): void
    {
        $logs      = new CapturedProtocolLogs();
        $failure   = new RuntimeException(self::MARKER, 37, new RuntimeException(self::MARKER));
        $connector = $this->createStub(SocketConnector::class);
        $connector->method('connect')->willThrowException($failure);
        $connection = new Connection($connector, new ConnectionConfiguration('127.0.0.1', 4222), $logs);
        try {
            $connection->open();
            self::fail('Expected connection failure');
        } catch (ConnectionException $exception) {
            self::assertSame(self::MARKER, $exception->getMessage());
            self::assertSame(37, $exception->getCode());
            self::assertSame($failure, $exception->getPrevious());
        }
        self::assertStringContainsString('tcp_open', $logs->output());
        $logs->assertSafe([self::MARKER]);
    }

    #[DataProvider('socketFailures')]
    public function testSocketFailuresDoNotLogFramesOrOriginalException(string $operation): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($operation): void {
            $logs   = new CapturedProtocolLogs();
            $socket = $operation === 'write' ? $this->createMock(Socket::class) : $this->createStub(Socket::class);
            $socket->method('isClosed')->willReturn(false);
            if ($operation === 'read') {
                $socket->method('read')->willThrowException(new RuntimeException(self::MARKER, 38));
            } elseif ($operation === 'parse') {
                $socket->method('read')->willReturn(self::MARKER . "\r\n");
            } else {
                $socket->method('read')->willReturn(null);
                $socket->expects(self::once())->method('write')
                    ->with(self::callback(static fn (string $wire): bool => str_contains($wire, self::MARKER)))
                    ->willThrowException(new RuntimeException(self::MARKER, 39));
            }
            $connector = $this->createStub(SocketConnector::class);
            $connector->method('connect')->willReturn($socket);
            $connection = new Connection($connector, new ConnectionConfiguration('127.0.0.1', 4222), $logs);
            try {
                $connection->open();
                try {
                    if ($operation === 'write') {
                        $connection->send(new ConnectMessage(new ConnectInfo(false, false, false, 'php', PHP_VERSION, pass: self::MARKER)));
                    } else {
                        $connection->receive();
                    }
                    self::fail('Expected socket or parser failure');
                } catch (ConnectionException | RuntimeException $exception) {
                    self::assertStringContainsString(self::MARKER, $exception->getMessage());
                }
            } finally {
                $connection->close();
                $this->forceTick();
            }
            self::assertStringContainsString($operation === 'write' ? 'write' : 'read', $logs->output());
            $logs->assertSafe([self::MARKER]);
        });
    }

    public static function socketFailures(): iterable
    {
        foreach (['read', 'write', 'parse'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('clientFailures')]
    public function testClientFailureBranchesUseSafeMetadata(string $operation): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($operation): void {
            $logs       = new CapturedProtocolLogs();
            $reads      = 0;
            $connection = new ScriptedConnection(
                static function (ScriptedConnection $connection) use (&$reads): NatsProtocolMessageInterface|null {
                    return $reads++ === 0 ? new InfoMessage(self::INFO) : $connection->closeAndReturnNull();
                },
                $operation === 'open' ? static fn () => throw new ConnectionException(self::MARKER, 40) : null,
                $operation === 'write' ? static function (NatsProtocolMessageInterface $message): void {
                    if ($message->getType() === NatsMessageType::CONNECT) {
                        throw new RuntimeException(self::MARKER, 41);
                    }
                } : null,
            );
            $storage    = new SubscriptionStorage();
            if ($operation === 'dispatch') {
                $dispatcher = $this->createStub(MessageDispatcherInterface::class);
                $dispatcher->method('dispatch')->willThrowException(new RuntimeException(self::MARKER, 42));
            } else {
                $dispatcher = new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, pass: self::MARKER), $storage, $logs);
            }
            $client = new Client(new ClientConfiguration(), new NullCancellation(), $connection, new EventDispatcher(), $dispatcher, $storage, $logs);
            try {
                try {
                    $client->connect();
                    self::assertNotSame('open', $operation);
                    $this->forceTick();
                } catch (ConnectionException $exception) {
                    self::assertSame('open', $operation);
                    self::assertSame(self::MARKER, $exception->getMessage());
                }
            } finally {
                $client->disconnect();
                $this->forceTick();
            }
            if ($operation === 'write') {
                self::assertStringContainsString(self::MARKER, $connection->sentWire()[0]);
            }
            self::assertStringContainsString('ERROR', $logs->output());
            $logs->assertSafe([self::MARKER]);
        });
    }

    public static function clientFailures(): iterable
    {
        foreach (['open', 'write', 'dispatch'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testReconnectFailureDoesNotLogExceptionContents(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $logs       = new CapturedProtocolLogs();
            $opens      = 0;
            $reads      = 0;
            $connection = new ScriptedConnection(
                static function () use (&$reads): NatsProtocolMessageInterface {
                    if ($reads++ === 0) {
                        return new InfoMessage(self::INFO);
                    }
                    throw new RuntimeException(self::MARKER, 43);
                },
                static function () use (&$opens): void {
                    if (++$opens > 1) {
                        throw new ConnectionException(self::MARKER, 44);
                    }
                },
            );
            $storage    = new SubscriptionStorage();
            $client     = new Client(
                new ClientConfiguration(reconnectEnabled: true, maxReconnectAttempts: 1, reconnectBackoffInitialMs: 1, reconnectJitterFraction: 0),
                new NullCancellation(),
                $connection,
                new EventDispatcher(),
                new MessageDispatcher(new ConnectInfo(false, false, false, 'php', PHP_VERSION, pass: self::MARKER), $storage, $logs),
                $storage,
                $logs,
            );
            try {
                $client->connect();
                $deadline = hrtime(true) + 2_000_000_000;
                while (!str_contains($logs->output(), 'Reconnect attempts are exhausted')) {
                    if (hrtime(true) >= $deadline) {
                        self::fail('Reconnect failure was not observed');
                    }
                    \Amp\delay(0.005);
                }
                self::assertSame(2, $opens);
                self::assertStringContainsString('Reconnect attempt failed', $logs->output());
            } finally {
                $client->disconnect();
                $this->forceTick();
            }
            $logs->assertSafe([self::MARKER]);
        });
    }

    public function testCallbackExceptionCannotEchoPayloadIntoLogs(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $logs      = new CapturedProtocolLogs();
            $scheduler = new InboundDispatchScheduler(1, 1, $logs);
            $scheduler->dispatch(new MsgMessage('audit.test', 'sid1', self::MARKER), static fn () => throw new RuntimeException(self::MARKER));
            $this->forceTick();
            self::assertStringContainsString('inbound.dispatch.callback_error', $logs->output());
            $logs->assertSafe([self::MARKER]);
        });
    }
}
