<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Message;

use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use PHPUnit\Framework\TestCase;

final class JetStreamMessageTest extends TestCase
{
    public function testMessageActsAsDto(): void
    {
        // Arrange
        $message = new JetStreamMessage(
            subject: 'orders.created',
            payload: 'payload',
            headers: ['Nats-Msg-Id' => 'id-1'],
            replyTo: '$JS.ACK.ORDERS.C1.1',
            sizeBytes: 123,
            deliveryCount: 2,
        );

        // Act
        $subject = $message->getSubject();
        $payload = $message->getPayload();
        $headers = $message->getHeaders();
        $replyTo = $message->getReplyTo();
        $size    = $message->getSizeBytes();
        $count   = $message->getDeliveryCount();

        // Assert
        self::assertSame('orders.created', $subject);
        self::assertSame('payload', $payload);
        self::assertSame(['Nats-Msg-Id' => 'id-1'], $headers);
        self::assertSame('$JS.ACK.ORDERS.C1.1', $replyTo);
        self::assertSame(123, $size);
        self::assertSame(2, $count);
    }
}
