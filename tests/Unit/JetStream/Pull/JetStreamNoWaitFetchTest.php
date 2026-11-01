<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Pull;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

use function Amp\async;

final class JetStreamNoWaitFetchTest extends TestCase
{
    use AsyncTestTools;

    public function testNoWaitWaitsForReplyOnNextEventLoopTurn(): void
    {
        $this->runAsyncTest(function (): void {
            $delivered = false;
            $consumer  = $this->consumer(static function (\Closure $handler, array $request) use (&$delivered): void {
                self::assertSame(['batch' => 1, 'no_wait' => true], $request);
                EventLoop::queue(static function () use ($handler, &$delivered): void {
                    $delivered = true;
                    $handler(self::data('next-turn'));
                });
            });
            $result    = $consumer->fetch(1, 100, true);
            self::assertSame(1, $result->getReceivedCount());
            self::assertTrue($delivered);
            self::assertSame('next-turn', iterator_to_array($result->messages())[0]->getPayload());
        });
    }

    public static function endings(): iterable
    {
        yield 'empty' => [0, 404, 'No Messages', true];
        yield 'partial' => [2, 404, 'No Messages', true];
        yield 'server expiry' => [2, 408, 'Request Timeout', false];
        yield 'byte limit' => [2, 409, 'Message Size Exceeds MaxBytes', true];
        yield 'batch completed' => [2, 409, 'Batch Completed', true];
    }

    #[DataProvider('endings')]
    public function testTerminalStatusReturnsWithoutWaitingForDeadline(int $count, int $code, string $description, bool $noWait): void
    {
        $this->runAsyncTest(function () use ($count, $code, $description, $noWait): void {
            $consumer = $this->consumer(static function (\Closure $handler, array $request) use ($count, $code, $description, $noWait): void {
                if ($noWait) {
                    self::assertArrayNotHasKey('expires', $request);
                    self::assertArrayNotHasKey('idle_heartbeat', $request);
                } else {
                    self::assertSame(2_000_000_000, $request['expires']);
                    self::assertSame(20_000_000, $request['idle_heartbeat']);
                }

                EventLoop::queue(static function () use ($handler, $count, $code, $description): void {
                    for ($i = 0; $i < $count; $i++) {
                        $handler(self::data('message-' . $i));
                    }

                    $handler(self::statusMessage($code, $description));
                });
            });
            $start    = hrtime(true);
            $result   = $consumer->fetch(5, 2000, $noWait, 10000, 20);
            self::assertLessThan(1_000_000_000, hrtime(true) - $start);
            self::assertSame($count, $result->getReceivedCount());
            self::assertSame(array_map(static fn(int $i): string => 'message-' . $i, $count === 0 ? [] : range(0, $count - 1)), array_map(static fn($m): string => $m->getPayload(), iterator_to_array($result->messages())));
        });
    }

    public static function missingCompletion(): iterable
    {
        yield 'silence' => [false];
        yield 'partial without terminal' => [true];
    }

    #[DataProvider('missingCompletion')]
    public function testLocalTimeoutIsAnErrorEvenAfterPartialData(bool $partial): void
    {
        $this->runAsyncTest(function () use ($partial): void {
            $consumer = $this->consumer(static function (\Closure $handler) use ($partial): void {
                if ($partial) {
                    EventLoop::queue(static fn() => $handler(self::data('partial')));
                }
            });
            $start    = hrtime(true);
            try {
                $consumer->fetch(5, 20, true);
                self::fail('Missing completion must time out');
            } catch (JetStreamTimeoutException $error) {
                self::assertSame(408, $error->getApiCode());
                self::assertGreaterThanOrEqual(15_000_000, hrtime(true) - $start);
                self::assertLessThan(1_000_000_000, hrtime(true) - $start);
            }
        });
    }

    public static function errors(): iterable
    {
        yield [400, 'Bad Request: invalid heartbeat'];
        yield [409, 'Consumer Deleted'];
        yield [503, 'No Responders'];
    }

    #[DataProvider('errors')]
    public function testServerErrorRetainsCodeAndDescription(int $code, string $description): void
    {
        $this->runAsyncTest(function () use ($code, $description): void {
            $consumer = $this->consumer(static function (\Closure $handler) use ($code, $description): void {
                EventLoop::queue(static fn() => $handler(self::statusMessage($code, $description)));
            });
            try {
                $consumer->fetch(1, 100, true);
                self::fail('Expected server error');
            } catch (JetStreamApiException $error) {
                self::assertSame($code, $error->getApiCode());
                self::assertSame($description, $error->getDescription());
            }
        });
    }

    public function testHeartbeatAndLateCallbacksDoNotBecomeMessages(): void
    {
        $this->runAsyncTest(function (): void {
            $late     = null;
            $consumer = $this->consumer(static function (\Closure $handler) use (&$late): void {
                $late = $handler;
                EventLoop::queue(static function () use ($handler): void {
                    $handler(self::statusMessage(100, 'Idle Heartbeat'));
                    $handler(self::data('only'));
                    $handler(self::data('late-in-same-turn'));
                    $handler(self::statusMessage(500, 'Late error'));
                });
            });
            $result   = $consumer->fetch(1, 100, true);
            $late(self::data('after-unsubscribe'));
            $late(self::statusMessage(500, 'After completion'));
            self::assertSame(1, $result->getReceivedCount());
            self::assertSame('only', iterator_to_array($result->messages())[0]->getPayload());
        });
    }

    public function testExactByteBudgetCompletesPartialBatchWithoutStatus(): void
    {
        $this->runAsyncTest(function (): void {
            $message  = self::data("AB\r\n\0");
            $budget   = strlen($message->getSubject()) + strlen($message->getReplyTo()) + strlen($message->getPayload());
            $consumer = $this->consumer(static function (\Closure $handler, array $request) use ($message, $budget): void {
                self::assertSame($budget, $request['max_bytes']);
                EventLoop::queue(static fn() => $handler($message));
            });
            $result   = $consumer->fetch(5, 100, true, $budget);
            self::assertSame(1, $result->getReceivedCount());
            self::assertSame("AB\r\n\0", iterator_to_array($result->messages())[0]->getPayload());
        });
    }

    public function testExactHeaderByteBudgetUsesReceivedBytesBeforeHeaderNormalization(): void
    {
        $this->runAsyncTest(function (): void {
            $block   = "NATS/1.0\r\nX: a:b\r\nX:    c\r\n\r\n";
            $payload = "AB\r\n\0";
            $headers = strlen($block);
            $total   = $headers + strlen($payload);
            $subject = 'orders.created';
            $reply   = 'ACK.ORDERS.C1.1';
            $message = null;
            $parser  = new ProtocolParser(static function ($parsed) use (&$message): void {
                $message = $parsed;
            });
            $parser->push("HMSG $subject sid1 $reply $headers $total\r\n$block$payload\r\n");
            $budget   = strlen($subject) + strlen($reply) + $total;
            $consumer = $this->consumer(static function (\Closure $handler) use ($message): void {
                EventLoop::queue(static fn() => $handler($message));
            });
            $result   = $consumer->fetch(5, 100, true, $budget);
            self::assertSame(1, $result->getReceivedCount());
            self::assertSame($payload, iterator_to_array($result->messages())[0]->getPayload());
        });
    }

    public function testThirdPartyHeaderMessageWithoutStatusCapabilityRemainsCompatible(): void
    {
        $this->runAsyncTest(function (): void {
            $message = $this->createStubForIntersectionOfInterfaces([
                \Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface::class,
                NatsProtocolMessageInterface::class,
            ]);
            $message->method('getSubject')->willReturn('orders.created');
            $message->method('getReplyTo')->willReturn('ACK.ORDERS.C1.1');
            $message->method('getPayload')->willReturn('third-party');
            $message->method('getHeaders')->willReturn(new \Dorpmaster\Nats\Protocol\Header\HeaderBag(['X' => 'value']));
            $consumer = $this->consumer(static function (\Closure $handler) use ($message): void {
                EventLoop::queue(static fn() => $handler($message));
            });
            self::assertSame('third-party', iterator_to_array($consumer->fetch(1, 100, true)->messages())[0]->getPayload());
        });
    }

    public function testNoWaitFalseKeepsLocalTimeoutPartialResult(): void
    {
        $this->runAsyncTest(function (): void {
            $consumer = $this->consumer(static function (\Closure $handler): void {
                EventLoop::queue(static fn() => $handler(self::data('partial')));
            });
            self::assertSame(1, $consumer->fetch(5, 1, false)->getReceivedCount());
        });
    }

    public function testPublishFailureKeepsOriginalErrorWhenCleanupFails(): void
    {
        $this->runAsyncTest(function (): void {
            $original = new RuntimeException('Synthetic publish failure');
            $consumer = $this->consumer(static function () use ($original): void {
                throw $original;
            }, static function (): void {
                throw new RuntimeException('Synthetic cleanup failure');
            });
            try {
                $consumer->fetch(1, 50, true);
                self::fail('Expected original error');
            } catch (RuntimeException $error) {
                self::assertSame($original, $error);
            }
        });
    }

    public function testCallbackFailureKeepsOriginalErrorWhenCleanupFails(): void
    {
        $this->runAsyncTest(function (): void {
            $original = new RuntimeException('Synthetic decoding failure');
            $message  = $this->createStubForIntersectionOfInterfaces([\Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface::class, NatsProtocolMessageInterface::class]);
            $message->method('getReplyTo')->willThrowException($original);
            $consumer = $this->consumer(static function (\Closure $handler) use ($message): void {
                EventLoop::queue(static fn() => $handler($message));
            }, static function (): void {
                throw new RuntimeException('Synthetic cleanup failure');
            });
            try {
                $consumer->fetch(1, 50, true);
                self::fail('Expected original error');
            } catch (RuntimeException $error) {
                self::assertSame($original, $error);
            }
        });
    }

    public function testParallelFetchesHaveIndependentInboxesAndCompletion(): void
    {
        $this->runAsyncTest(function (): void {
            $requests = [];
            $consumer = $this->consumer(static function (\Closure $handler, array $request, string $inbox) use (&$requests): void {
                $requests[$inbox] = $handler;
                if (count($requests) === 2) {
                    EventLoop::queue(static function () use (&$requests): void {
                        $handlers = array_values($requests);
                        $handlers[1](self::data('second'));
                        $handlers[0](self::data('first'));
                        $handlers[0](self::statusMessage(404, 'No Messages'));
                    });
                }
            }, subscriptions: 2);
            $first    = async(static fn() => $consumer->fetch(5, 200, true));
            $second   = async(static fn() => $consumer->fetch(1, 50, true));
            self::assertSame('first', iterator_to_array($first->await()->messages())[0]->getPayload());
            self::assertSame('second', iterator_to_array($second->await()->messages())[0]->getPayload());
            self::assertCount(2, $requests);
        });
    }

    public function testOneConcurrentTimeoutDoesNotCompleteOtherFetch(): void
    {
        $this->runAsyncTest(function (): void {
            $consumer = $this->consumer(static function (\Closure $handler, array $request): void {
                if ($request['batch'] === 2) {
                    EventLoop::delay(0.04, static function () use ($handler): void {
                        $handler(self::data('survivor'));
                        $handler(self::statusMessage(404, 'No Messages'));
                    });
                }
            }, subscriptions: 2);
            $first    = async(static fn() => $consumer->fetch(1, 10, true));
            $second   = async(static fn() => $consumer->fetch(2, 100, true));
            try {
                $first->await();
                self::fail('First fetch must time out');
            } catch (JetStreamTimeoutException) {
                self::assertSame(1, $second->await()->getReceivedCount());
            }
        });
    }

    public function testTimeoutCleansTimerAndIgnoresLateReplyEvenIfUnsubscribeFails(): void
    {
        $this->runAsyncTest(function (): void {
            $late     = null;
            $consumer = $this->consumer(static function (\Closure $handler) use (&$late): void {
                $late = $handler;
            }, static function (): void {
                throw new RuntimeException('Synthetic cleanup failure');
            });
            $before   = EventLoop::getIdentifiers();
            try {
                $consumer->fetch(1, 10, true);
                self::fail('Expected timeout');
            } catch (JetStreamTimeoutException $error) {
                self::assertSame(408, $error->getApiCode());
            }

            $late(self::data('too late'));
            $late(self::statusMessage(503, 'Too late'));
            $this->forceTick();
            self::assertSame($before, EventLoop::getIdentifiers());
        });
    }

    public function testSuccessfulFetchCleansTimer(): void
    {
        $this->runAsyncTest(function (): void {
            $consumer = $this->consumer(static function (\Closure $handler): void {
                EventLoop::queue(static fn() => $handler(self::data('ok')));
            });
            $before   = EventLoop::getIdentifiers();
            self::assertSame(1, $consumer->fetch(1, 100, true)->getReceivedCount());
            $this->forceTick();
            self::assertSame($before, EventLoop::getIdentifiers());
        });
    }

    private function consumer(\Closure $publish, \Closure|null $cleanup = null, int $subscriptions = 1): JetStreamPullConsumer
    {
        $handlers = [];
        $cleaned  = [];
        $client   = $this->createMock(ClientInterface::class);
        $client->expects(self::exactly($subscriptions))->method('subscribe')->willReturnCallback(static function (string $subject, \Closure $handler) use (&$handlers): string {
            $handlers[$subject] = $handler;
            return $subject;
        });
        $client->expects(self::exactly($subscriptions))->method('unsubscribe')->willReturnCallback(static function (string $sid) use (&$cleaned, &$handlers, $cleanup): void {
            self::assertArrayHasKey($sid, $handlers);
            self::assertArrayNotHasKey($sid, $cleaned);
            $cleaned[$sid] = true;
            $cleanup?->__invoke();
        });
        $index  = 0;
        $helper = $this->createStub(SubscriptionIdHelperInterface::class);
        $helper->method('generateId')->willReturnCallback(static function () use (&$index): string {
            return 'fetch' . ++$index;
        });
        $transport = $this->createStub(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($client);
        $transport->method('getSubscriptionIdHelper')->willReturn($helper);
        $transport->method('publishRequest')->willReturnCallback(static function (string $subject, array $request, string $inbox) use ($publish, &$handlers): void {
            $publish($handlers[$inbox], $request, $inbox);
        });

        return new JetStreamPullConsumer($transport, 'ORDERS', 'C1');
    }

    private static function data(string $payload): MsgMessage
    {
        return new MsgMessage('orders.created', 'sid1', $payload, 'ACK.ORDERS.C1.1');
    }

    private static function statusMessage(int $code, string $description): NatsProtocolMessageInterface
    {
        $message = null;
        $parser  = new ProtocolParser(static function ($parsed) use (&$message): void {
            $message = $parsed;
        });
        $block   = "NATS/1.0 $code $description\r\n\r\n";
        $size    = strlen($block);
        $parser->push("HMSG INBOX.fetch1 sid1 $size $size\r\n$block\r\n");
        self::assertNotNull($message);
        return $message;
    }
}
