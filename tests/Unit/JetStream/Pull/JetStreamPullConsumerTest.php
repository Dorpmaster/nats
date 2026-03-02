<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Pull;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class JetStreamPullConsumerTest extends TestCase
{
    public function testFetchCollectsMessagesAndAcksAreAvailable(): void
    {
        // Arrange
        $handler = null;

        $client = $this->createMock(ClientInterface::class);
        $client->method('subscribe')
            ->willReturnCallback(static function (string $subject, \Closure $closure) use (&$handler): string {
                self::assertSame('INBOX.sid-1', $subject);
                $handler = $closure;

                return 'sid';
            });
        $client->expects(self::once())->method('unsubscribe')->with('sid');

        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($client);
        $transport->method('getSubscriptionIdHelper')->willReturn($helper = $this->createStub(SubscriptionIdHelperInterface::class));
        $helper->method('generateId')->willReturn('sid-1');
        $transport->expects(self::once())
            ->method('publishRequest')
            ->with(
                'CONSUMER.MSG.NEXT.ORDERS.C1',
                ['batch' => 2, 'expires' => 1000000000, 'no_wait' => false],
                'INBOX.sid-1',
            )
            ->willReturnCallback(function () use (&$handler): void {
                self::assertInstanceOf(\Closure::class, $handler);
                $handler(new MsgMessage('orders.created', '1', 'm1', 'ACK.ORDERS.C1.1'));
                $handler(new MsgMessage('orders.created', '2', 'm2', 'ACK.ORDERS.C1.2'));
            });

        $logger   = new RecordingLogger();
        $consumer = new JetStreamPullConsumer($transport, 'ORDERS', 'C1', logger: $logger);

        // Act
        $result   = $consumer->fetch(batch: 2, expiresMs: 1000);
        $messages = iterator_to_array($result->messages());

        // Assert
        self::assertSame(2, $result->getReceivedCount());
        self::assertCount(2, $messages);
        self::assertSame('m1', $messages[0]->getPayload());
        self::assertSame('m2', $messages[1]->getPayload());
        $logger->assertHas(LogLevel::DEBUG, 'js.pull.fetch', static fn (array $context): bool => ($context['stream'] ?? null) === 'ORDERS');
        $logger->assertHas(LogLevel::DEBUG, 'js.pull.fetch.result', static fn (array $context): bool => ($context['received'] ?? null) === 2);
    }

    public function testFetchThrowsOnJsonErrorResponse(): void
    {
        // Arrange
        $handler = null;

        $client = $this->createMock(ClientInterface::class);
        $client->method('subscribe')
            ->willReturnCallback(static function (string $_subject, \Closure $closure) use (&$handler): string {
                $handler = $closure;

                return 'sid';
            });
        $client->expects(self::once())->method('unsubscribe')->with('sid');

        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($client);
        $transport->method('getSubscriptionIdHelper')->willReturn($helper = $this->createStub(SubscriptionIdHelperInterface::class));
        $helper->method('generateId')->willReturn('sid-2');
        $transport->expects(self::once())
            ->method('publishRequest')
            ->willReturnCallback(function () use (&$handler): void {
                $handler(new MsgMessage('inbox', '1', '{"error":{"code":409,"description":"consumer is deleted"}}'));
            });

        $logger   = new RecordingLogger();
        $consumer = new JetStreamPullConsumer($transport, 'ORDERS', 'C1', logger: $logger);

        // Assert
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('consumer is deleted');

        // Act
        $consumer->fetch(batch: 1, expiresMs: 500, noWait: true);
    }

    public function testFetchReturnsEmptyResultOnNoWaitNoMessages(): void
    {
        // Arrange
        $handler = null;

        $client = $this->createMock(ClientInterface::class);
        $client->method('subscribe')
            ->willReturnCallback(static function (string $_subject, \Closure $closure) use (&$handler): string {
                $handler = $closure;

                return 'sid';
            });
        $client->expects(self::once())->method('unsubscribe')->with('sid');

        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($client);
        $transport->method('getSubscriptionIdHelper')->willReturn($helper = $this->createStub(SubscriptionIdHelperInterface::class));
        $helper->method('generateId')->willReturn('sid-3');
        $transport->expects(self::once())
            ->method('publishRequest')
            ->willReturnCallback(function () use (&$handler): void {
                $handler(new MsgMessage('inbox', '1', '{"error":{"code":404,"description":"no messages"}}'));
            });

        $logger   = new RecordingLogger();
        $consumer = new JetStreamPullConsumer($transport, 'ORDERS', 'C1', logger: $logger);

        // Act
        $result = $consumer->fetch(batch: 1, expiresMs: 200, noWait: true);

        // Assert
        self::assertSame(0, $result->getReceivedCount());
        $logger->assertHas(LogLevel::DEBUG, 'js.pull.fetch.empty', static fn (array $context): bool => ($context['code'] ?? null) === 404);
    }

    public function testConsumeReturnsMessagesViaHandle(): void
    {
        // Arrange
        $handler   = null;
        $fetchCall = 0;

        $client = $this->createMock(ClientInterface::class);
        $client->method('subscribe')
            ->willReturnCallback(static function (string $_subject, \Closure $closure) use (&$handler): string {
                $handler = $closure;

                return 'sid';
            });
        $client->expects(self::atLeastOnce())->method('unsubscribe')->with('sid');

        $transport = $this->createStub(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($client);
        $transport->method('getSubscriptionIdHelper')->willReturn($helper = $this->createStub(SubscriptionIdHelperInterface::class));
        $helper->method('generateId')->willReturn('sid-4');
        $transport->method('publishRequest')
            ->willReturnCallback(function () use (&$handler, &$fetchCall): void {
                $fetchCall++;
                if ($fetchCall !== 1) {
                    return;
                }

                $handler(new MsgMessage('orders.created', '1', 'm1', 'ACK.ORDERS.C1.1'));
            });

        $consumer = new JetStreamPullConsumer($transport, 'ORDERS', 'C1');
        $options  = new PullConsumeOptions(batch: 1, expiresMs: 100, noWait: true);

        // Act
        $handle  = $consumer->consume($options);
        $message = $handle->next(500);
        $handle->stop();
        $none = $handle->next(200);

        // Assert
        self::assertSame('m1', $message?->getPayload());
        self::assertNull($none);
    }

    public function testFetchParsesDeliveryCountFromHeaders(): void
    {
        // Arrange
        $handler = null;
        $client  = $this->createMock(ClientInterface::class);
        $client->method('subscribe')
            ->willReturnCallback(static function (string $_subject, \Closure $closure) use (&$handler): string {
                $handler = $closure;

                return 'sid';
            });
        $client->expects(self::once())->method('unsubscribe')->with('sid');

        $transport = $this->createStub(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($client);
        $transport->method('getSubscriptionIdHelper')->willReturn($helper = $this->createStub(SubscriptionIdHelperInterface::class));
        $helper->method('generateId')->willReturn('sid-5');
        $transport->method('publishRequest')
            ->willReturnCallback(function () use (&$handler): void {
                $handler(new HMsgMessage(
                    subject: 'orders.created',
                    sid: '1',
                    payload: 'm1',
                    headers: new HeaderBag(['Nats-Num-Delivered' => '6']),
                    replyTo: 'ACK.ORDERS.C1.1',
                ));
            });

        $consumer = new JetStreamPullConsumer($transport, 'ORDERS', 'C1');

        // Act
        $result   = $consumer->fetch(batch: 1, expiresMs: 1000);
        $messages = iterator_to_array($result->messages());

        // Assert
        self::assertCount(1, $messages);
        self::assertSame(6, $messages[0]->getDeliveryCount());
    }
}
