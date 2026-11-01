<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\SignalCancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use Dorpmaster\Nats\Tests\Support\HMsgHeaderCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamPullConsumerIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::requireJetStreamMode();
        NatsServerHarness::waitUntilReady();
    }

    public function testFetchAndAckMessages(): void
    {
        self::requireJetStreamMode();

        // Arrange
        $client = $this->createClient();
        $client->connect();

        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $publisher = new JetStreamPublisher($client);
        $factory   = new JetStreamPullConsumerFactory($transport);

        $stream   = 'ORDERS_PULL';
        $consumer = 'C1';

        $admin->createStream(new StreamConfig($stream, ['orders.*']));
        $admin->createOrUpdateConsumer($stream, new ConsumerConfig($consumer, filterSubject: 'orders.created'));

        for ($index = 1; $index <= 5; $index++) {
            $publisher->publish('orders.created', 'msg-' . $index);
        }

        try {
            // Act
            $pullConsumer = $factory->create($stream, $consumer);
            $result       = $pullConsumer->fetch(batch: 5, expiresMs: 2000);
            $messages     = iterator_to_array($result->messages());
            $acker        = $result->getAcker();

            foreach ($messages as $message) {
                $acker->ack($message);
            }

            $consumerInfo = $admin->getConsumerInfo($stream, $consumer);

            // Assert
            self::assertSame(5, $result->getReceivedCount());
            self::assertCount(5, $messages);
            self::assertSame('msg-1', $messages[0]->getPayload());
            self::assertSame('msg-5', $messages[4]->getPayload());
            self::assertNotNull($consumerInfo->getNumPending());
            self::assertSame(0, $consumerInfo->getNumPending());
        } finally {
            $admin->deleteConsumer($stream, $consumer);
            $admin->deleteStream($stream);
            $client->disconnect();
        }
    }

    public function testFetchNoWaitOnEmptyConsumerReturnsEmptyResult(): void
    {
        self::requireJetStreamMode();

        // Arrange
        $client = $this->createClient();
        $client->connect();

        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $factory   = new JetStreamPullConsumerFactory($transport);

        $stream   = 'ORDERS_PULL_EMPTY';
        $consumer = 'C1';

        $admin->createStream(new StreamConfig($stream, ['orders.empty.*']));
        $admin->createOrUpdateConsumer($stream, new ConsumerConfig($consumer, filterSubject: 'orders.empty.created'));

        try {
            // Act
            $pullConsumer = $factory->create($stream, $consumer);
            $result       = $pullConsumer->fetch(batch: 1, expiresMs: 100, noWait: true);

            // Assert
            self::assertSame(0, $result->getReceivedCount());
        } finally {
            $admin->deleteConsumer($stream, $consumer);
            $admin->deleteStream($stream);
            $client->disconnect();
        }
    }

    public static function availableBatches(): iterable
    {
        yield 'full one' => [1, 1];
        yield 'partial two of five' => [2, 5];
        yield 'empty' => [0, 5];
    }

    #[DataProvider('availableBatches')]
    public function testNoWaitReceivesAvailableBatchAndKeepsConnectionUsable(int $available, int $batch): void
    {
        self::requireJetStreamMode();
        $reconnections = 0;
        $events        = new EventDispatcher();
        $events->subscribe('connectionStatusChanged', static function (string $event, ClientState $state) use (&$reconnections): void {
            if ($state === ClientState::RECONNECTING) {
                $reconnections++;
            }
        });
        $client = $this->createClient($events);
        $client->connect();
        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $publisher = new JetStreamPublisher($client);
        $stream    = 'NOWAIT_' . $available;
        $subject   = 'nowait.' . $available;
        $admin->createStream(new StreamConfig($stream, [$subject]));
        $admin->createOrUpdateConsumer($stream, new ConsumerConfig('C1', filterSubject: $subject));
        try {
            $expected = [];
            for ($i = 0; $i < $available; $i++) {
                $expected[] = "msg-$i\r\n\0";
                $publisher->publish($subject, $expected[$i]);
            }

            $pull     = (new JetStreamPullConsumerFactory($transport))->create($stream, 'C1');
            $start    = hrtime(true);
            $result   = $pull->fetch($batch, 3000, true, idleHeartbeatMs: 100);
            $elapsed  = hrtime(true) - $start;
            $messages = iterator_to_array($result->messages());
            self::assertSame($available, $result->getReceivedCount());
            self::assertSame($expected, array_map(static fn($message): string => $message->getPayload(), $messages));
            self::assertLessThan(2_000_000_000, $elapsed, 'Server completion must precede the local deadline');
            foreach ($messages as $message) {
                $result->getAcker()->ack($message);
            }

            // Both the API request and the next pull use the existing connected client.
            self::assertSame(0, $admin->getConsumerInfo($stream, 'C1')->getNumPending());
            self::assertSame(0, $pull->fetch(1, 1000, true)->getReceivedCount());
            self::assertSame(0, $reconnections);
        } finally {
            $admin->deleteConsumer($stream, 'C1');
            $admin->deleteStream($stream);
            $client->disconnect();
        }
    }

    public function testNoWaitMaxBytesReturnsPartialBatchAndKeepsConnectionUsable(): void
    {
        self::requireJetStreamMode();
        $reconnections = 0;
        $events        = new EventDispatcher();
        $events->subscribe('connectionStatusChanged', static function (string $event, ClientState $state) use (&$reconnections): void {
            if ($state === ClientState::RECONNECTING) {
                $reconnections++;
            }
        });
        $client = $this->createClient($events);
        $client->connect();
        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $publisher = new JetStreamPublisher($client);
        $stream    = 'NOWAIT_BYTES';
        $subject   = 'nowait.bytes';
        $admin->createStream(new StreamConfig($stream, [$subject]));
        $admin->createOrUpdateConsumer($stream, new ConsumerConfig('C1', filterSubject: $subject));
        try {
            $publisher->publish($subject, 'short');
            $publisher->publish($subject, str_repeat('large', 300));
            $pull   = (new JetStreamPullConsumerFactory($transport))->create($stream, 'C1');
            $start  = hrtime(true);
            $result = $pull->fetch(5, 3000, true, 300);
            self::assertSame(1, $result->getReceivedCount());
            self::assertLessThan(2_000_000_000, hrtime(true) - $start);
            $message = iterator_to_array($result->messages())[0];
            self::assertSame('short', $message->getPayload());
            $result->getAcker()->ack($message);
            self::assertSame(1, $admin->getConsumerInfo($stream, 'C1')->getNumPending());
            self::assertSame(1, $pull->fetch(1, 1000, true)->getReceivedCount());
            self::assertSame(0, $reconnections);
        } finally {
            $admin->deleteConsumer($stream, 'C1');
            $admin->deleteStream($stream);
            $client->disconnect();
        }
    }

    public static function headerContentCases(): iterable
    {
        return HMsgHeaderCases::cases();
    }

    /** @param array<string, list<string>> $headers */
    #[DataProvider('headerContentCases')]
    public function testHeaderValuesWithNatsSurviveJetStreamPublishAndPull(array $headers): void
    {
        self::requireJetStreamMode();
        $reconnections = 0;
        $events        = new EventDispatcher();
        $events->subscribe('connectionStatusChanged', static function (string $event, ClientState $state) use (&$reconnections): void {
            if ($state === ClientState::RECONNECTING) {
                $reconnections++;
            }
        });
        $client    = $this->createClient($events);
        $transport = new JetStreamControlPlaneTransport($client);
        $admin     = new JetStreamAdmin($transport);
        $token     = strtoupper(bin2hex(random_bytes(6)));
        $stream    = 'HMSG_' . $token;
        $subject   = 'hmsg.content.' . $token;
        $payload   = "jetstream\r\n\0PUB is payload\r\nend\0";
        $created   = false;
        try {
            $client->connect();
            $admin->createStream(new StreamConfig($stream, [$subject]));
            $created = true;
            $admin->createOrUpdateConsumer($stream, new ConsumerConfig('C1', filterSubject: $subject));
            // The public publish/pull DTO contract exposes one string per header name.
            $singleValues = array_map(static fn(array $values): string => $values[0], $headers);
            $publisher    = new JetStreamPublisher($client);
            self::assertSame($stream, $publisher->publish($subject, $payload, PublishOptions::create(headers: $singleValues))->getStream());
            $pull   = (new JetStreamPullConsumerFactory($transport))->create($stream, 'C1');
            $result = $pull->fetch(1, 2000, true);
            self::assertSame(1, $result->getReceivedCount());
            $message = iterator_to_array($result->messages())[0];
            self::assertSame($payload, $message->getPayload());
            self::assertSame($singleValues, $message->getHeaders());
            $result->getAcker()->ack($message);

            // Inspect a real JetStream pull HMSG too, including every repeated value.
            $ack = json_decode($client->request(new HPubMessage($subject, $payload, new HeaderBag($headers)), 2)->getPayload(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($stream, $ack['stream']);
            $inbox    = 'INBOX.HMSG' . $token;
            $received = new DeferredFuture();
            $sid      = $client->subscribe($inbox, static function ($message) use ($received): null {
                if (!$received->isComplete()) {
                    $received->complete($message);
                }
                return null;
            });
            try {
                $transport->publishRequest('CONSUMER.MSG.NEXT.' . $stream . '.C1', ['batch' => 1, 'no_wait' => true], $inbox);
                $raw = $received->getFuture()->await(new TimeoutCancellation(2));
            } finally {
                $client->unsubscribe($sid);
            }

            self::assertInstanceOf(HMsgMessage::class, $raw);
            self::assertSame($headers, iterator_to_array($raw->getHeaders()));
            self::assertSame($payload, $raw->getPayload());
            self::assertNull($raw->getStatus());
            $client->publish(new PubMessage((string) $raw->getReplyTo(), '+ACK'));
            self::assertSame(0, $admin->getConsumerInfo($stream, 'C1')->getNumPending());
            self::assertSame(0, $pull->fetch(1, 1000, true)->getReceivedCount());
            self::assertSame(0, $reconnections);
        } finally {
            try {
                if ($created) {
                    $admin->deleteStream($stream);
                }
            } finally {
                $client->disconnect();
            }
        }
    }

    private function createClient(EventDispatcher|null $events = null): Client
    {
        $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

        $connector = new RetrySocketConnector(new DnsSocketConnector());
        $config    = new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port());

        $connection          = new Connection($connector, $config);
        $clientConfiguration = new ClientConfiguration();
        $eventDispatcher     = $events ?? new EventDispatcher();

        $connectionInfo    = new ConnectInfo(
            verbose: false,
            pedantic: false,
            tls_required: false,
            lang: 'php',
            version: PHP_VERSION,
            protocol: 1,
            headers: true,
        );
        $storage           = new SubscriptionStorage();
        $messageDispatcher = new MessageDispatcher($connectionInfo, $storage);

        return new Client(
            configuration: $clientConfiguration,
            cancellation: $cancellation,
            connection: $connection,
            eventDispatcher: $eventDispatcher,
            messageDispatcher: $messageDispatcher,
            storage: $storage,
        );
    }

    private static function requireJetStreamMode(): void
    {
        if ((string) (getenv('NATS_JS') ?: '0') !== '1') {
            self::markTestSkipped('JetStream integration is disabled. Run with NATS_JS=1.');
        }
    }
}
