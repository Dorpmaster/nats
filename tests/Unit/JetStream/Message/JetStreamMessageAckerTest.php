<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Message;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcker;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use PHPUnit\Framework\TestCase;

final class JetStreamMessageAckerTest extends TestCase
{
    public function testAckPublishesAckPayload(): void
    {
        // Arrange
        $acknowledger = $this->createMock(JetStreamMessageAcknowledgerInterface::class);
        $acknowledger->expects(self::once())
            ->method('acknowledge')
            ->with('$JS.ACK.ORDERS.C1.1', '+ACK');

        $message = new JetStreamMessage('orders.created', 'payload', [], '$JS.ACK.ORDERS.C1.1');
        $acker   = new JetStreamMessageAcker($acknowledger);

        // Act
        $acker->ack($message);

        // Assert
        self::assertTrue(true);
    }

    public function testNakTermAndInProgressPublishExpectedPayloads(): void
    {
        // Arrange
        $acknowledger = $this->createMock(JetStreamMessageAcknowledgerInterface::class);
        $acknowledger->expects(self::exactly(3))
            ->method('acknowledge')
            ->with(
                '$JS.ACK.ORDERS.C1.1',
                self::callback(static function (string $payload): bool {
                    self::assertContains($payload, ['-NAK', '+TERM', '+WPI']);
                    return true;
                }),
            );

        $message = new JetStreamMessage('orders.created', 'payload', [], '$JS.ACK.ORDERS.C1.1');
        $acker   = new JetStreamMessageAcker($acknowledger);

        // Act
        $acker->nak($message);
        $acker->term($message);
        $acker->inProgress($message);

        // Assert
        self::assertTrue(true);
    }

    public function testNakWithDelayUsesNanoseconds(): void
    {
        // Arrange
        $acknowledger = $this->createMock(JetStreamMessageAcknowledgerInterface::class);
        $acknowledger->expects(self::once())
            ->method('acknowledge')
            ->with('$JS.ACK.ORDERS.C1.1', '-NAK 250000000');

        $message = new JetStreamMessage('orders.created', 'payload', [], '$JS.ACK.ORDERS.C1.1');
        $acker   = new JetStreamMessageAcker($acknowledger);

        // Act
        $acker->nak($message, 250);

        // Assert
        self::assertTrue(true);
    }

    public function testAckThrowsWhenReplySubjectMissing(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $message      = new JetStreamMessage('orders.created', 'payload', [], null);
        $acker        = new JetStreamMessageAcker($acknowledger);

        // Assert
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('Missing reply subject for ack');

        // Act
        $acker->ack($message);
    }
}
