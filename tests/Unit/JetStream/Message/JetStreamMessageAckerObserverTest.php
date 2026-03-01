<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Message;

use Dorpmaster\Nats\Domain\JetStream\Message\AckObserverInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcker;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageInterface;
use PHPUnit\Framework\TestCase;

final class JetStreamMessageAckerObserverTest extends TestCase
{
    public function testObserverNotifiedOnAck(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $message      = new JetStreamMessage('orders.created', 'payload', [], '$JS.ACK.ORDERS.C1.1', 7);
        $observer     = new class () implements AckObserverInterface {
            public int $count = 0;

            public function onMessageAcknowledged(JetStreamMessageInterface $message): void
            {
                $this->count++;
            }
        };
        $acker->observe($message, $observer);

        // Act
        $acker->ack($message);

        // Assert
        self::assertSame(1, $observer->count);
    }

    public function testObserverNotNotifiedOnInProgress(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $message      = new JetStreamMessage('orders.created', 'payload', [], '$JS.ACK.ORDERS.C1.1', 7);
        $observer     = new class () implements AckObserverInterface {
            public int $count = 0;

            public function onMessageAcknowledged(JetStreamMessageInterface $message): void
            {
                $this->count++;
            }
        };
        $acker->observe($message, $observer);

        // Act
        $acker->inProgress($message);

        // Assert
        self::assertSame(0, $observer->count);
    }
}
