<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SubscriptionStorage;
use Dorpmaster\Nats\Domain\Client\ClientState;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Event\EventDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PongMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class InboundDispatchExecutionModelTest extends TestCase
{
    use AsyncTestTools;

    public function testSecondCallbackStartsBeforeFirstCompletes(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst  = new DeferredFuture();
            $firstStarted  = new DeferredFuture();
            $secondStarted = new DeferredFuture();
            $events        = [];

            $storage = new SubscriptionStorage();
            $storage->add('sid', static function (MsgMessage $message) use (
                $releaseFirst,
                $firstStarted,
                $secondStarted,
                &$events,
            ): null {
                if ($message->getPayload() === 'first') {
                    $events[] = 'first-start';
                    $firstStarted->complete();
                    $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
                    $events[] = 'first-complete';

                    return null;
                }

                $events[] = 'second-start';
                $secondStarted->complete();

                return null;
            });

            $client = $this->createClient(
                $this->createPlannedConnection([
                    [new MsgMessage('subject', 'sid', 'first'), new MsgMessage('subject', 'sid', 'second'), new CancelledException()],
                ]),
                $storage,
            );

            $client->connect();

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));
            $secondStarted->getFuture()->await(new TimeoutCancellation(1));

            self::assertSame(['first-start', 'second-start'], $events);

            $releaseFirst->complete();

            $client->disconnect();
        });
    }

    public function testReaderLoopAdvancesWhilePreviousCallbackRuns(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst  = new DeferredFuture();
            $firstStarted  = new DeferredFuture();
            $secondReceive = new DeferredFuture();
            $receiveCalls  = 0;

            $storage = new SubscriptionStorage();
            $storage->add('sid', static function (MsgMessage $message) use ($releaseFirst, $firstStarted): null {
                if ($message->getPayload() === 'first') {
                    $firstStarted->complete();
                    $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
                }

                return null;
            });

            $client = $this->createClient(
                $this->createPlannedConnection([
                    [
                        static function () use (&$receiveCalls): MsgMessage {
                            $receiveCalls++;

                            return new MsgMessage('subject', 'sid', 'first');
                        },
                        static function () use (&$receiveCalls, $secondReceive): MsgMessage {
                            $receiveCalls++;
                            $secondReceive->complete();

                            return new MsgMessage('subject', 'sid', 'second');
                        },
                        static function () use (&$receiveCalls): never {
                            $receiveCalls++;
                            throw new CancelledException();
                        },
                    ],
                ]),
                $storage,
            );

            $client->connect();

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));
            $secondReceive->getFuture()->await(new TimeoutCancellation(1));

            self::assertGreaterThanOrEqual(2, $receiveCalls);

            $releaseFirst->complete();

            $client->disconnect();
        });
    }

    public function testCallbackExceptionDoesNotKillInboundDispatch(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $secondStarted = new DeferredFuture();
            $events        = [];

            $storage = new SubscriptionStorage();
            $storage->add('sid', static function (MsgMessage $message) use ($secondStarted, &$events): null {
                if ($message->getPayload() === 'first') {
                    $events[] = 'first-throw';
                    throw new \RuntimeException('boom');
                }

                $events[] = 'second-start';
                $secondStarted->complete();

                return null;
            });

            $client = $this->createClient(
                $this->createPlannedConnection([
                    [new MsgMessage('subject', 'sid', 'first'), new MsgMessage('subject', 'sid', 'second'), new CancelledException()],
                ]),
                $storage,
            );

            $client->connect();

            $secondStarted->getFuture()->await(new TimeoutCancellation(1));

            self::assertSame(['first-throw', 'second-start'], $events);

            $client->disconnect();
        });
    }

    public function testDrainWaitsForActiveDispatchTasks(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst   = new DeferredFuture();
            $firstStarted   = new DeferredFuture();
            $drainCompleted = false;

            $storage = new SubscriptionStorage();
            $storage->add('sid', static function (MsgMessage $message) use ($releaseFirst, $firstStarted): null {
                if ($message->getPayload() === 'first') {
                    $firstStarted->complete();
                    $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
                }

                return null;
            });

            $client = $this->createClient(
                $this->createPlannedConnection([
                    [new MsgMessage('subject', 'sid', 'first'), new CancelledException()],
                ]),
                $storage,
            );

            $client->connect();

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));

            $drainFuture = async(function () use ($client, &$drainCompleted): void {
                $client->drain(1_000);
                $drainCompleted = true;
            });

            $this->forceTick();

            self::assertSame(ClientState::DRAINING, $client->getState());
            self::assertFalse($drainCompleted);

            $releaseFirst->complete();

            $drainFuture->await(new TimeoutCancellation(1));

            self::assertTrue($drainCompleted);
            self::assertSame(ClientState::CLOSED, $client->getState());
        });
    }

    public function testReconnectKeepsAsyncDispatchOperational(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst  = new DeferredFuture();
            $firstStarted  = new DeferredFuture();
            $thirdStarted  = new DeferredFuture();
            $events        = [];
            $openCalls     = 0;
            $secondStarted = false;

            $storage = new SubscriptionStorage();
            $storage->add('sid', static function (MsgMessage $message) use (
                $releaseFirst,
                $firstStarted,
                $thirdStarted,
                &$events,
                &$secondStarted,
            ): null {
                if ($message->getPayload() === 'first') {
                    $events[] = 'first-start';
                    $firstStarted->complete();
                    $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
                    $events[] = 'first-complete';

                    return null;
                }

                if ($message->getPayload() === 'second') {
                    $secondStarted = true;

                    return null;
                }

                $events[] = 'third-start';
                $thirdStarted->complete();

                return null;
            });

            $client = $this->createClient(
                $this->createPlannedConnection(
                    [
                        [
                            new MsgMessage('subject', 'sid', 'first'),
                            new MsgMessage('subject', 'sid', 'second'),
                            new \RuntimeException('connection lost'),
                        ],
                        [new MsgMessage('subject', 'sid', 'third'), new CancelledException()],
                    ],
                    $openCalls,
                ),
                $storage,
                new ClientConfiguration(
                    reconnectEnabled: true,
                    maxReconnectAttempts: 1,
                    reconnectJitterFraction: 0.0,
                    maxInboundDispatchConcurrency: 1,
                    maxPendingInboundDispatch: 1,
                ),
            );

            $client->connect();

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));
            $thirdStarted->getFuture()->await(new TimeoutCancellation(1));

            self::assertGreaterThanOrEqual(2, $openCalls);
            self::assertSame(['first-start', 'third-start'], $events);
            self::assertFalse($secondStarted);

            $releaseFirst->complete();

            $client->disconnect();
        });
    }

    public function testControlMessagesAreNotBlockedBySchedulerSaturation(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst = new DeferredFuture();
            $firstStarted = new DeferredFuture();
            $pongSent     = new DeferredFuture();
            $pongObserved = false;

            $storage = new SubscriptionStorage();
            $storage->add('sid', static function (MsgMessage $message) use ($releaseFirst, $firstStarted): null {
                $firstStarted->complete();
                $releaseFirst->getFuture()->await(new TimeoutCancellation(1));

                return null;
            });

            $client = $this->createClient(
                $this->createPlannedConnection(
                    [
                        [new MsgMessage('subject', 'sid', 'first'), new PingMessage(), new CancelledException()],
                    ],
                    onSend: static function (NatsProtocolMessageInterface $message) use ($pongSent): void {
                        if ($message instanceof PongMessage) {
                            $pongSent->complete();
                        }
                    },
                ),
                $storage,
                new ClientConfiguration(maxInboundDispatchConcurrency: 1, maxPendingInboundDispatch: 1),
            );

            $client->connect();

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));
            $pongSent->getFuture()->await(new TimeoutCancellation(1));

            $pongObserved = true;
            self::assertTrue($pongObserved);

            $releaseFirst->complete();
            $client->disconnect();
        });
    }

    public function testPendingQueueOverflowTriggersControlledFailurePath(): void
    {
        $this->setTimeout(5);
        $this->runAsyncTest(function () {
            $releaseFirst = new DeferredFuture();
            $firstStarted = new DeferredFuture();

            $storage = new SubscriptionStorage();
            $storage->add('sid', static function (MsgMessage $message) use ($releaseFirst, $firstStarted): null {
                if ($message->getPayload() === 'first') {
                    $firstStarted->complete();
                    $releaseFirst->getFuture()->await(new TimeoutCancellation(1));
                }

                return null;
            });

            $client = $this->createClient(
                $this->createPlannedConnection([
                    [
                        new MsgMessage('subject', 'sid', 'first'),
                        new MsgMessage('subject', 'sid', 'second'),
                        new MsgMessage('subject', 'sid', 'third'),
                    ],
                ]),
                $storage,
                new ClientConfiguration(
                    reconnectEnabled: false,
                    maxInboundDispatchConcurrency: 1,
                    maxPendingInboundDispatch: 1,
                ),
            );

            $client->connect();

            $firstStarted->getFuture()->await(new TimeoutCancellation(1));

            for ($i = 0; $i < 5 && $client->getState() !== ClientState::CLOSED; $i++) {
                $this->forceTick();
            }

            self::assertSame(ClientState::CLOSED, $client->getState());

            $releaseFirst->complete();
        });
    }

    private function createClient(
        ConnectionInterface $connection,
        SubscriptionStorage $storage,
        ClientConfiguration|null $configuration = null,
    ): Client {
        return new Client(
            configuration: $configuration ?? new ClientConfiguration(),
            cancellation: new NullCancellation(),
            connection: $connection,
            eventDispatcher: new EventDispatcher(),
            messageDispatcher: new MessageDispatcher(
                new ConnectInfo(false, false, false, 'php', '8.5'),
                $storage,
                $this->logger,
            ),
            storage: $storage,
            logger: $this->logger,
        );
    }

    /**
     * @param list<list<NatsProtocolMessageInterface|\Closure|CancelledException|\RuntimeException>> $plans
     */
    private function createPlannedConnection(
        array $plans,
        int &$openCalls = 0,
        \Closure|null $onSend = null,
    ): ConnectionInterface {
        $incrementOpenCalls = static function () use (&$openCalls): void {
            $openCalls++;
        };

        return new class ($plans, $incrementOpenCalls, $onSend) implements ConnectionInterface {
            private bool $closed      = true;
            private int $activePlan   = -1;
            private int $receiveIndex = 0;
            private int $openCalls    = 0;

            /**
             * @param list<list<NatsProtocolMessageInterface|\Closure|CancelledException|\RuntimeException>> $plans
             */
            public function __construct(
                private readonly array $plans,
                private readonly \Closure $incrementOpenCalls,
                private readonly \Closure|null $onSend,
            ) {
            }

            public function open(Cancellation|null $cancellation = null): void
            {
                $this->openCalls++;
                ($this->incrementOpenCalls)();
                $this->activePlan   = min($this->openCalls - 1, count($this->plans) - 1);
                $this->receiveIndex = 0;
                $this->closed       = false;
            }

            public function close(): void
            {
                $this->closed = true;
            }

            public function isClosed(): bool
            {
                return $this->closed;
            }

            public function receive(Cancellation|null $cancellation = null): NatsProtocolMessageInterface|null
            {
                $plan = $this->plans[$this->activePlan] ?? [];
                $step = $plan[$this->receiveIndex] ?? new CancelledException();
                $this->receiveIndex++;

                if ($step instanceof \Throwable) {
                    throw $step;
                }

                if ($step instanceof \Closure) {
                    $step = $step();
                }

                return $step;
            }

            public function send(NatsProtocolMessageInterface $message): void
            {
                $this->onSend?->__invoke($message);
            }
        };
    }
}
