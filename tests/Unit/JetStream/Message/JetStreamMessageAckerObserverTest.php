<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Message;

use Dorpmaster\Nats\Domain\JetStream\Message\AckObserverInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcker;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageInterface;
use Dorpmaster\Nats\Domain\JetStream\Pull\LoggingJetStreamMessageAcker;
use Dorpmaster\Nats\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class JetStreamMessageAckerObserverTest extends TestCase
{
    public function testObserverNotifiedOnAck(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $logger       = new RecordingLogger();
        $acker        = new LoggingJetStreamMessageAcker(new JetStreamMessageAcker($acknowledger), $logger);
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
        $logger->assertHas(LogLevel::DEBUG, 'js.ack.send', static fn (array $context): bool => ($context['kind'] ?? null) === '+ACK');
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

    public function testMissingReplyLogsError(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $logger       = new RecordingLogger();
        $acker        = new LoggingJetStreamMessageAcker(new JetStreamMessageAcker($acknowledger), $logger);
        $message      = new JetStreamMessage('orders.created', 'payload', [], null, 7);

        // Assert
        self::expectException(JetStreamApiException::class);

        // Act
        try {
            $acker->ack($message);
        } catch (\Throwable $exception) {
            $logger->assertHas(LogLevel::ERROR, 'js.ack.missing_reply', static fn (array $context): bool => ($context['subject'] ?? null) === 'orders.created');
            throw $exception;
        }
    }
}
