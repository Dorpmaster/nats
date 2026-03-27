<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\SignalCancellation;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Connection\Connection;
use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\NatsServerHarness;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class ClientQueueSubscriptionIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function setUpBeforeClass(): void
    {
        NatsServerHarness::waitUntilReady();
    }

    public function testMessagesAreLoadBalancedAcrossSameQueueGroup(): void
    {
        $this->setTimeout(40);
        $this->runAsyncTest(function (): void {
            $subject   = sprintf('it.queue.lb.%s', bin2hex(random_bytes(6)));
            $payloads  = $this->payloads('msg', 50);
            $receivedA = [];
            $receivedB = [];
            $clientA   = $this->createClient();
            $clientB   = $this->createClient();
            $publisher = $this->createClient();

            try {
                $clientA->connect();
                $clientB->connect();
                $publisher->connect();

                $clientA->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$receivedA): null {
                    $receivedA[] = $message->getPayload();

                    return null;
                }, 'workers');
                $clientB->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$receivedB): null {
                    $receivedB[] = $message->getPayload();

                    return null;
                }, 'workers');

                $this->awaitQueueReady($publisher, $subject, $receivedA, $receivedB, 3.0);
                $receivedA = [];
                $receivedB = [];

                $this->publishBatch($publisher, $subject, $payloads);
                $this->awaitTotalMessages($receivedA, $receivedB, count($payloads), 5.0);

                $all = [...$receivedA, ...$receivedB];

                self::assertCount(count($payloads), $all);
                self::assertCount(count($payloads), array_unique($all));
                self::assertGreaterThan(0, count($receivedA));
                self::assertGreaterThan(0, count($receivedB));
            } finally {
                $publisher->disconnect();
                $clientB->disconnect();
                $clientA->disconnect();
            }
        });
    }

    public function testRegularSubscriptionStillReceivesAllMessagesAlongsideQueueGroup(): void
    {
        $this->setTimeout(40);
        $this->runAsyncTest(function (): void {
            $subject         = sprintf('it.queue.broadcast.%s', bin2hex(random_bytes(6)));
            $payloads        = $this->payloads('broadcast', 30);
            $queueReceivedA  = [];
            $queueReceivedB  = [];
            $regularReceived = [];
            $clientA         = $this->createClient();
            $clientB         = $this->createClient();
            $clientC         = $this->createClient();
            $publisher       = $this->createClient();

            try {
                $clientA->connect();
                $clientB->connect();
                $clientC->connect();
                $publisher->connect();

                $clientA->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$queueReceivedA): null {
                    $queueReceivedA[] = $message->getPayload();

                    return null;
                }, 'workers');
                $clientB->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$queueReceivedB): null {
                    $queueReceivedB[] = $message->getPayload();

                    return null;
                }, 'workers');
                $clientC->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$regularReceived): null {
                    $regularReceived[] = $message->getPayload();

                    return null;
                });

                $this->awaitQueueAndRegularReady(
                    $publisher,
                    $subject,
                    $queueReceivedA,
                    $queueReceivedB,
                    $regularReceived,
                    3.0,
                );
                $queueReceivedA  = [];
                $queueReceivedB  = [];
                $regularReceived = [];

                $this->publishBatch($publisher, $subject, $payloads);
                $this->awaitTotalMessages($queueReceivedA, $queueReceivedB, count($payloads), 5.0);
                $this->awaitSingleStreamCount($regularReceived, count($payloads), 5.0);

                $queueAll = [...$queueReceivedA, ...$queueReceivedB];

                self::assertCount(count($payloads), $queueAll);
                self::assertCount(count($payloads), array_unique($queueAll));
                self::assertCount(count($payloads), $regularReceived);
                self::assertCount(count($payloads), array_unique($regularReceived));
            } finally {
                $publisher->disconnect();
                $clientC->disconnect();
                $clientB->disconnect();
                $clientA->disconnect();
            }
        });
    }

    public function testQueueSubscriptionIsRestoredAfterReconnect(): void
    {
        $this->setTimeout(60);
        $this->runAsyncTest(function (): void {
            $subject        = sprintf('it.queue.reconnect.%s', bin2hex(random_bytes(6)));
            $beforePayloads = $this->payloads('before', 12);
            $afterPayloads  = $this->payloads('after', 20);
            $beforeA        = [];
            $beforeB        = [];
            $afterA         = [];
            $afterB         = [];
            $probeReceived  = [];
            $clientA        = $this->createReconnectClient();
            $clientB        = $this->createReconnectClient();
            $publisher      = $this->createClient();

            try {
                $clientA->connect();
                $clientB->connect();
                $publisher->connect();

                $clientA->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$beforeA, &$afterA, &$probeReceived): null {
                    $payload = $message->getPayload();
                    if (str_starts_with($payload, 'before-')) {
                        $beforeA[] = $payload;
                    } elseif (str_starts_with($payload, 'after-')) {
                        $afterA[] = $payload;
                    } elseif ($payload === 'probe-after-reconnect') {
                        $probeReceived[] = $payload;
                    }

                    return null;
                }, 'workers');
                $clientB->subscribe($subject, static function (NatsProtocolMessageInterface $message) use (&$beforeB, &$afterB, &$probeReceived): null {
                    $payload = $message->getPayload();
                    if (str_starts_with($payload, 'before-')) {
                        $beforeB[] = $payload;
                    } elseif (str_starts_with($payload, 'after-')) {
                        $afterB[] = $payload;
                    } elseif ($payload === 'probe-after-reconnect') {
                        $probeReceived[] = $payload;
                    }

                    return null;
                }, 'workers');

                $this->awaitQueueReady($publisher, $subject, $beforeA, $beforeB, 3.0, 'before-ready');
                $beforeA = [];
                $beforeB = [];

                $this->publishBatch($publisher, $subject, $beforePayloads);
                $this->awaitTotalMessages($beforeA, $beforeB, count($beforePayloads), 5.0);

                NatsServerHarness::restart();
                NatsServerHarness::waitUntilReady();
                $this->awaitClientsConnected([$clientA, $clientB], 6.0);

                $publisher->disconnect();
                $publisher = $this->createClient();
                $publisher->connect();

                $this->awaitProbeDelivery($publisher, $subject, $probeReceived, 5.0);

                $this->publishBatch($publisher, $subject, $afterPayloads);
                $this->awaitTotalMessages($afterA, $afterB, count($afterPayloads), 6.0);

                $beforeAll = [...$beforeA, ...$beforeB];
                $afterAll  = [...$afterA, ...$afterB];

                self::assertCount(count($beforePayloads), $beforeAll);
                self::assertCount(count($beforePayloads), array_unique($beforeAll));
                self::assertCount(count($afterPayloads), $afterAll);
                self::assertCount(count($afterPayloads), array_unique($afterAll));
                self::assertGreaterThan(0, count($afterA) + count($afterB));
            } finally {
                NatsServerHarness::ensureUp();
                $publisher->disconnect();
                $clientB->disconnect();
                $clientA->disconnect();
            }
        });
    }

    private function createClient(ClientConfiguration|null $clientConfiguration = null): Client
    {
        $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);
        $connector    = new RetrySocketConnector(new DnsSocketConnector());
        $connection   = new Connection(
            $connector,
            new ConnectionConfiguration(NatsServerHarness::host(), NatsServerHarness::port()),
            $this->logger,
        );
        $storage      = new SubscriptionStorage();

        return new Client(
            configuration: $clientConfiguration ?? new ClientConfiguration(),
            cancellation: $cancellation,
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(
                new ConnectInfo(false, false, false, 'php', PHP_VERSION),
                $storage,
                $this->logger,
            ),
            storage: $storage,
            logger: $this->logger,
        );
    }

    private function createReconnectClient(): Client
    {
        return $this->createClient(new ClientConfiguration(
            reconnectEnabled: true,
            maxReconnectAttempts: 20,
            reconnectBackoffInitialMs: 50,
            reconnectBackoffMaxMs: 500,
            reconnectBackoffMultiplier: 2.0,
            reconnectJitterFraction: 0.0,
            servers: [new ServerAddress(NatsServerHarness::host(), NatsServerHarness::port())],
        ));
    }

    /** @return list<string> */
    private function payloads(string $prefix, int $count): array
    {
        $payloads = [];
        for ($i = 1; $i <= $count; $i++) {
            $payloads[] = sprintf('%s-%d', $prefix, $i);
        }

        return $payloads;
    }

    /** @param list<string> $payloads */
    private function publishBatch(Client $publisher, string $subject, array $payloads): void
    {
        foreach ($payloads as $payload) {
            $publisher->publish(new PubMessage($subject, $payload));
        }
    }

    /** @param list<string> $receivedA
     *  @param list<string> $receivedB
     */
    private function awaitTotalMessages(array &$receivedA, array &$receivedB, int $expectedCount, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while ((count($receivedA) + count($receivedB)) < $expectedCount && microtime(true) < $deadline) {
            delay(0.05);
        }

        self::assertSame($expectedCount, count($receivedA) + count($receivedB));
    }

    /** @param list<string> $received */
    private function awaitSingleStreamCount(array &$received, int $expectedCount, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (count($received) < $expectedCount && microtime(true) < $deadline) {
            delay(0.05);
        }

        self::assertSame($expectedCount, count($received));
    }

    /** @param list<string> $probeReceived */
    private function awaitProbeDelivery(Client $publisher, string $subject, array &$probeReceived, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while ($probeReceived === [] && microtime(true) < $deadline) {
            $publisher->publish(new PubMessage($subject, 'probe-after-reconnect'));
            delay(0.05);
        }

        self::assertNotSame([], $probeReceived);
    }

    /** @param list<string> $receivedA
     *  @param list<string> $receivedB
     */
    private function awaitQueueReady(
        Client $publisher,
        string $subject,
        array &$receivedA,
        array &$receivedB,
        float $timeoutSeconds,
        string $probePayload = 'probe-queue-ready',
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;
        while ((count($receivedA) + count($receivedB)) === 0 && microtime(true) < $deadline) {
            $publisher->publish(new PubMessage($subject, $probePayload));
            delay(0.05);
        }

        self::assertGreaterThan(0, count($receivedA) + count($receivedB));
    }

    /** @param list<string> $queueReceivedA
     *  @param list<string> $queueReceivedB
     *  @param list<string> $regularReceived
     */
    private function awaitQueueAndRegularReady(
        Client $publisher,
        string $subject,
        array &$queueReceivedA,
        array &$queueReceivedB,
        array &$regularReceived,
        float $timeoutSeconds,
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;
        while (
            ((count($queueReceivedA) + count($queueReceivedB)) === 0 || count($regularReceived) === 0)
            && microtime(true) < $deadline
        ) {
            $publisher->publish(new PubMessage($subject, 'probe-regular-ready'));
            delay(0.05);
        }

        self::assertGreaterThan(0, count($queueReceivedA) + count($queueReceivedB));
        self::assertGreaterThan(0, count($regularReceived));
    }

    /** @param list<Client> $clients */
    private function awaitClientsConnected(array $clients, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $allConnected = true;
            foreach ($clients as $client) {
                if ($client->getState()->value !== 'CONNECTED') {
                    $allConnected = false;
                    break;
                }
            }

            if ($allConnected) {
                return;
            }

            delay(0.05);
        }

        self::fail('Not all queue subscription clients reconnected within the timeout window.');
    }
}
