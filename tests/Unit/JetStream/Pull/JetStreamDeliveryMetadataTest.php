<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Pull;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\SlowConsumerPolicy;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class JetStreamDeliveryMetadataTest extends TestCase
{
    use AsyncTestTools;

    public static function deliveryCases(): iterable
    {
        yield 'classic first MSG' => ['$JS.ACK.ORDERS.C1.1.10.20.1700000000000000000.0', null, 1];
        yield 'classic repeated MSG' => ['$JS.ACK.ORDERS.C1.2.10.21.1700000000000000000.0', null, 2];
        yield 'ACK two header 999' => ['$JS.ACK.ORDERS.C1.2.10.21.1700000000000000000.0', '999', 2];
        yield 'ACK six header one' => ['$JS.ACK.ORDERS.C1.6.10.25.1700000000000000000.0', '1', 6];
        yield 'extended eleven MSG' => ['$JS.ACK.EU.hash.ORDERS.C1.2.10.21.1700000000000000000.0', null, 2];
        yield 'extended twelve HMSG' => ['$JS.ACK.EU.hash.ORDERS.C1.6.10.25.1700000000000000000.0.random', '1', 6];
        yield 'numeric extension MSG' => ['$JS.ACK.EU.hash.ORDERS.C1.6.10.25.1700000000000000000.0.7', null, 6];
        yield 'numeric extension HMSG' => ['$JS.ACK.EU.hash.ORDERS.C1.6.10.25.1700000000000000000.0.7', '1', 6];
        yield 'multiple numeric extensions' => ['$JS.ACK.EU.hash.ORDERS.C1.6.10.25.1700000000000000000.0.0.123.token', '1', 6];
        yield 'empty domain placeholder' => ['$JS.ACK._.hash.ORDERS.C1.2.10.21.1700000000000000000.0', '999', 2];
        yield 'future extended tail' => ['$JS.ACK.EU.hash.ORDERS.C1.2.10.21.1700000000000000000.0.random.extra', '999', 2];
        yield 'wrong prefix with spoofed header' => ['OTHER.ACK.ORDERS.C1.2.10.21.1700000000000000000.0', '999', null];
        yield 'truncated' => ['$JS.ACK.ORDERS.C1.2', '999', null];
        yield 'unsupported ten tokens' => ['$JS.ACK.ORDERS.C1.2.10.21.1700000000000000000.0.tail', '999', null];
        yield 'zero' => ['$JS.ACK.ORDERS.C1.0.10.21.1700000000000000000.0', '999', null];
        yield 'negative' => ['$JS.ACK.ORDERS.C1.-2.10.21.1700000000000000000.0', '999', null];
        yield 'exponent' => ['$JS.ACK.ORDERS.C1.2e3.10.21.1700000000000000000.0', '999', null];
        yield 'overflow' => ['$JS.ACK.ORDERS.C1.' . PHP_INT_MAX . '0.10.21.1700000000000000000.0', '999', null];
        yield 'absent ACK MSG' => [null, null, null];
        yield 'absent ACK HMSG' => [null, '999', null];
    }

    #[DataProvider('deliveryCases')]
    public function testFetchUsesAckMetadataOnNextEventLoopTurn(string|null $reply, string|null $header, int|null $expected): void
    {
        $this->runAsyncTest(function () use ($reply, $header, $expected): void {
            $sent     = [];
            $consumer = $this->consumer([self::message($reply, $header)], $sent);
            $result   = $consumer->fetch(1, 100, true);
            $message  = iterator_to_array($result->messages())[0];
            self::assertSame($expected, $message->getDeliveryCount());
            self::assertSame($reply, $message->getReplyTo());
            self::assertSame("payload\r\n\0", $message->getPayload());
            self::assertSame($header === null ? [] : ['Nats-Num-Delivered' => $header], $message->getHeaders());
            self::assertSame([], $sent);
        });
    }

    public static function formats(): iterable
    {
        yield 'classic MSG' => ['$JS.ACK.ORDERS.C1.6.10.25.1700000000000000000.0', null];
        yield 'numeric extension reply stays intact' => ['$JS.ACK.EU.hash.ORDERS.C1.6.10.25.1700000000000000000.0.7', '1'];
        yield 'leading zeroes stay in reply' => ['$JS.ACK.ORDERS.C1.0006.00010.00025.01700000000000000000.0000', null];
        yield 'expanded HMSG' => ['$JS.ACK._.hash.ORDERS.C1.6.10.25.1700000000000000000.0.token', '1'];
    }

    #[DataProvider('formats')]
    public function testAckNakTermKeepTheOriginalReply(string $reply, string|null $header): void
    {
        $this->runAsyncTest(function () use ($reply, $header): void {
            $sent    = [];
            $result  = $this->consumer([self::message($reply, $header)], $sent)->fetch(1, 100, true);
            $message = iterator_to_array($result->messages())[0];
            self::assertSame(6, $message->getDeliveryCount());
            $result->getAcker()->ack($message);
            $result->getAcker()->nak($message);
            $result->getAcker()->term($message);
            self::assertSame([[$reply, '+ACK'], [$reply, '-NAK'], [$reply, '+TERM']], $sent);
        });
    }

    public static function dropCases(): iterable
    {
        yield 'MSG' => [false, false];
        yield 'HMSG conflicting headers' => [true, false];
        yield 'MSG numeric tail' => [false, true];
        yield 'HMSG numeric tail' => [true, true];
    }

    #[DataProvider('dropCases')]
    public function testConsumeDropNewUsesFetchedMetadataAndOnlyTermsDroppedSix(bool $headers, bool $numericTail): void
    {
        $this->runAsyncTest(function () use ($headers, $numericTail): void {
            $first = '$JS.ACK.ORDERS.C1.12.10.31.1700000000000000000.0';
            $five  = '$JS.ACK.ORDERS.C1.5.11.32.1700000000000000000.0';
            $six   = '$JS.ACK.ORDERS.C1.6.12.33.1700000000000000000.0';
            if ($numericTail) {
                // Both accepted and dropped messages have count 6 and a numeric extension.
                $first = '$JS.ACK.EU.hash.ORDERS.C1.6.10.25.1700000000000000000.0.7';
                $five  = '$JS.ACK.EU.hash.ORDERS.C1.5.11.32.1700000000000000000.0.7';
                $six   = '$JS.ACK.EU.hash.ORDERS.C1.6.12.33.1700000000000000000.0.7';
            }
            $invalid  = 'OTHER.ACK.ORDERS.C1.9.13.34.1700000000000000000.0';
            $sent     = [];
            $handle   = null;
            $consumer = $this->consumer([
                self::message($first, $headers ? '999' : null),
                self::message($five, $headers ? '999' : null),
                self::message($six, $headers ? '1' : null),
                self::message($invalid, $headers ? '999' : null),
            ], $sent, static function () use (&$handle): void {
                $handle->stop();
            }, 2);
            $handle   = $consumer->consume(new PullConsumeOptions(
                batch: 4,
                expiresMs: 100,
                noWait: true,
                maxInFlightMessages: 1,
                policy: SlowConsumerPolicy::DROP_NEW,
            ));
            try {
                $accepted = $handle->next(1000);
                self::assertNotNull($accepted);
                self::assertSame($numericTail ? 6 : 12, $accepted->getDeliveryCount());
                self::assertSame([], $sent);
                self::assertSame($first, $accepted->getReplyTo());
                self::assertNull($handle->next(1000));
                $handle->awaitStopped(1000);
                self::assertSame([[$six, '+TERM']], $sent);
                $handle->getAcker()->ack($accepted);
                self::assertSame(0, $handle->getInFlightMessages());
            } finally {
                $handle->stop();
                $handle->awaitStopped(1000);
            }
        });
    }

    /** @param list<NatsProtocolMessageInterface> $messages
     * @param list<array{string, string}> $sent
     */
    private function consumer(array $messages, array &$sent, \Closure|null $onEmpty = null, int $requests = 1): JetStreamPullConsumer
    {
        $handlers = [];
        $client   = $this->createMock(ClientInterface::class);
        $client->expects(self::exactly($requests))->method('subscribe')->willReturnCallback(static function (string $inbox, \Closure $handler) use (&$handlers): string {
            $handlers[$inbox] = $handler;
            return $inbox;
        });
        $client->expects(self::exactly($requests))->method('unsubscribe');
        $client->method('publish')->willReturnCallback(static function ($message) use (&$sent): void {
            $sent[] = [$message->getSubject(), $message->getPayload()];
        });
        $helper = $this->createStub(SubscriptionIdHelperInterface::class);
        $index  = 0;
        $helper->method('generateId')->willReturnCallback(static function () use (&$index): string {
            return 'metadata' . ++$index;
        });
        $transport = $this->createStub(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($client);
        $transport->method('getSubscriptionIdHelper')->willReturn($helper);
        $calls = 0;
        $transport->method('publishRequest')->willReturnCallback(static function (string $subject, array $request, string $inbox) use (&$calls, &$handlers, $messages, $onEmpty): void {
            $handler = $handlers[$inbox];
            $batch   = ++$calls === 1 ? $messages : [self::emptyStatus()];
            EventLoop::queue(static function () use ($handler, $batch, $calls, $onEmpty): void {
                foreach ($batch as $message) {
                    $handler($message);
                }
                if ($calls > 1) {
                    $onEmpty?->__invoke();
                }
            });
        });
        return new JetStreamPullConsumer($transport, 'ORDERS', 'C1');
    }

    private static function message(string|null $reply, string|null $header): NatsProtocolMessageInterface
    {
        $payload = "payload\r\n\0";
        $prefix  = 'orders.created sid' . ($reply === null ? '' : ' ' . $reply);
        if ($header === null) {
            return self::parse('MSG ' . $prefix . ' ' . strlen($payload) . "\r\n$payload\r\n");
        }
        $block = "NATS/1.0\r\nNats-Num-Delivered: $header\r\n\r\n";
        return self::parse('HMSG ' . $prefix . ' ' . strlen($block) . ' ' . (strlen($block) + strlen($payload)) . "\r\n$block$payload\r\n");
    }

    private static function emptyStatus(): NatsProtocolMessageInterface
    {
        $block = "NATS/1.0 404 No Messages\r\n\r\n";
        $size  = strlen($block);
        return self::parse("HMSG INBOX.metadata sid $size $size\r\n$block\r\n");
    }

    private static function parse(string $wire): NatsProtocolMessageInterface
    {
        $message = null;
        $parser  = new ProtocolParser(static function ($parsed) use (&$message): void {
            $message = $parsed;
        });
        $parser->push($wire);
        self::assertNotNull($message);
        return $message;
    }
}
