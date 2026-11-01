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
use Dorpmaster\Nats\Client\WriteBufferService;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneHeadersRequestMessage;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use Dorpmaster\Nats\Tests\Support\HMsgHeaderCases;
use PHPUnit\Framework\Attributes\DataProvider;
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
            $client  = $this->createClient();
            $subject = sprintf('it.lifecycle.%s', bin2hex(random_bytes(6)));

            try {
                // Act
                $client->connect();
                $client->disconnect();
                $client->disconnect();
                $client->connect();
                $sid      = $client->subscribe($subject, static function (MsgMessageInterface&NatsProtocolMessageInterface $message): PubMessageInterface {
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

    public function testPublishAndHeaderPublishPreserveBinaryPayload(): void
    {
        $this->setTimeout(30);
        $this->runAsyncTest(function (): void {
            $client  = $this->createClient(headers: true);
            $subject = 'it.binary.' . bin2hex(random_bytes(6));
            $payload = "before\r\nPUB untouched 1\r\n\0after";

            try {
                $client->connect();
                $client->subscribe($subject, static fn (NatsProtocolMessageInterface $message): PubMessage => new PubMessage((string) $message->getReplyTo(), $message->getPayload()));

                foreach ([new PubMessage($subject, $payload), new HPubMessage($subject, $payload, new HeaderBag(['Test' => 'binary']))] as $message) {
                    self::assertSame($payload, $client->request($message, 2)->getPayload());
                }
            } finally {
                $client->disconnect();
            }
        });
    }

    #[DataProvider('headerPublishImplementations')]
    public function testMutableHeaderPublishRoundtripUsesQueuedBytes(bool $jetstream, bool $withReply): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($jetstream, $withReply): void {
            $buffer   = new WriteBufferService(100, 100000);
            $client   = $this->createClient(headers: true, writeBuffer: $buffer);
            $token    = bin2hex(random_bytes(6));
            $subject  = 'it.hpub.snapshot.' . $token;
            $probe    = 'it.hpub.probe.' . $token;
            $replyTo  = $withReply ? 'it.hpub.reply.' . $token : null;
            $received = new DeferredFuture();
            $headers  = new HeaderBag(['X' => 'a']);
            $payload  = "AB\r\n\0PUB is-payload 1\r\nZ\0";
            $message  = $jetstream
                ? new JetStreamControlPlaneHeadersRequestMessage($subject, $payload, $headers, $replyTo)
                : new HPubMessage($subject, $payload, $headers, $replyTo);

            try {
                $client->connect();
                $client->subscribe($subject, static function (NatsProtocolMessageInterface $message) use ($received): null {
                    if (!$received->isComplete()) {
                        $received->complete($message);
                    }
                    return null;
                });
                $client->subscribe($probe, static fn (NatsProtocolMessageInterface $message): PubMessage => new PubMessage((string) $message->getReplyTo(), 'ready'));
                self::assertSame('ready', $client->request(new PubMessage($probe, 'before'), 2)->getPayload());
                $this->forceTick(); // Flush the probe's inbox unsubscribe before measuring bytes.

                $message->getHeaders()->set('X', ['abcdefgh', 'second']);
                $headers->set('Z', 'last');
                $snapshot = (string) $message;
                $buffer->pause();
                $this->forceTick(); // Let the paused writer finish before enqueue/resume.
                $client->publish($message);
                self::assertSame(strlen($snapshot), $buffer->getPendingBytes());
                $headers->set('X', 'changed after enqueue');
                $headers->set('Z', 'also changed');
                $buffer->resume();

                $actual = $received->getFuture()->await(new TimeoutCancellation(2));
                self::assertInstanceOf(HMsgMessageInterface::class, $actual);
                self::assertSame($payload, $actual->getPayload());
                self::assertSame($replyTo, $actual->getReplyTo());
                self::assertSame(['X', 'Z'], $actual->getHeaders()->names());
                self::assertSame(['abcdefgh', 'second'], $actual->getHeaders()->get('X'));
                self::assertSame('last', $actual->getHeaders()->get('Z'));
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame(0, $buffer->getPendingMessages());
                self::assertSame('ready', $client->request(new PubMessage($probe, 'after'), 2)->getPayload());
            } finally {
                $client->disconnect();
            }
        });
    }

    public static function headerPublishImplementations(): iterable
    {
        foreach ([false, true] as $jetstream) {
            foreach ([false, true] as $withReply) {
                yield ($jetstream ? 'jetstream' : 'core') . ($withReply ? '-reply' : '-no-reply') => [$jetstream, $withReply];
            }
        }
    }

    public static function headerContentCases(): iterable
    {
        return HMsgHeaderCases::cases();
    }

    /** @param array<string, list<string>> $headers */
    #[DataProvider('headerContentCases')]
    public function testHeaderValuesWithNatsSurviveCoreRoundtripAndNextRequest(array $headers): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($headers): void {
            $states = [];
            $events = new EventDispatcher();
            $events->subscribe('connectionStatusChanged', static function (string $event, ClientState $state) use (&$states): void {
                $states[] = $state;
            });
            $client   = $this->createClient(headers: true, events: $events);
            $token    = bin2hex(random_bytes(6));
            $subject  = 'it.hmsg.content.' . $token;
            $probe    = 'it.hmsg.probe.' . $token;
            $payload  = "binary\r\n\0PUB is payload\r\nend\0";
            $received = new DeferredFuture();
            try {
                $client->connect();
                $client->subscribe($subject, static function (NatsProtocolMessageInterface $message) use ($received): null {
                    if (!$received->isComplete()) {
                        $received->complete($message);
                    }
                    return null;
                });
                $client->subscribe($probe, static fn(NatsProtocolMessageInterface $message): PubMessage => new PubMessage((string) $message->getReplyTo(), 'ready'));
                self::assertSame('ready', $client->request(new PubMessage($probe, 'before'), 2)->getPayload());
                $client->publish(new HPubMessage($subject, $payload, new HeaderBag($headers)));
                $message = $received->getFuture()->await(new TimeoutCancellation(2));
                self::assertInstanceOf(HMsgMessageInterface::class, $message);
                self::assertSame($headers, iterator_to_array($message->getHeaders()));
                self::assertSame($payload, $message->getPayload());
                self::assertNull($message->getStatus());
                self::assertSame(strlen((string) new HeaderBag($headers)) + 4 + strlen($payload), $message->getWireBodySize());
                self::assertSame('ready', $client->request(new PubMessage($probe, 'after'), 2)->getPayload());
                self::assertNotContains(ClientState::RECONNECTING, $states);
                self::assertSame(1, count(array_filter($states, static fn(ClientState $state): bool => $state === ClientState::CONNECTED)));
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
                $sid      = $client->subscribe($subject, static function (MsgMessageInterface&NatsProtocolMessageInterface $message): PubMessageInterface {
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

    private function createClient(bool|null $headers = null, WriteBufferService|null $writeBuffer = null, EventDispatcher|null $events = null): Client
    {
        $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

        $connector = new RetrySocketConnector(new DnsSocketConnector());
        $config    = new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port());

        $connection          = new Connection($connector, $config, $this->logger);
        $clientConfiguration = new ClientConfiguration();
        $eventDispatcher     = $events ?? new EventDispatcher();

        $connectionInfo    = new ConnectInfo(false, false, false, 'php', PHP_VERSION, headers: $headers);
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
            writeBuffer: $writeBuffer,
        );
    }
}
