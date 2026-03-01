<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Pull;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamSlowConsumerException;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcker;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamConsumeHandle;
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use Dorpmaster\Nats\Domain\JetStream\Pull\SlowConsumerPolicy;
use PHPUnit\Framework\TestCase;

final class JetStreamConsumeHandleTest extends TestCase
{
    public function testNextReturnsQueuedMessagesInOrder(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $handle       = new JetStreamConsumeHandle($acker, new PullConsumeOptions(batch: 2, expiresMs: 1000));
        $first        = new JetStreamMessage('s', 'm1', [], 'r1', 2);
        $second       = new JetStreamMessage('s', 'm2', [], 'r2', 2);
        $handle->offer($first);
        $handle->offer($second);
        $handle->completeProducer();

        // Act
        $firstOut  = $handle->next(100);
        $secondOut = $handle->next(100);

        // Assert
        self::assertSame('m1', $firstOut?->getPayload());
        self::assertSame('m2', $secondOut?->getPayload());
    }

    public function testStopIsIdempotentAndEndsStream(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $handle       = new JetStreamConsumeHandle($acker, new PullConsumeOptions(batch: 1, expiresMs: 1000));
        $handle->offer(new JetStreamMessage('s', 'm1', [], 'r1', 2));

        // Act
        $handle->stop();
        $handle->stop();
        $first  = $handle->next(100);
        $second = $handle->next(100);

        // Assert
        self::assertSame('m1', $first?->getPayload());
        self::assertNull($second);
    }

    public function testBackpressureErrorThrows(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $options      = new PullConsumeOptions(
            batch: 2,
            expiresMs: 1000,
            maxInFlightMessages: 1,
            maxInFlightBytes: 1024,
            policy: SlowConsumerPolicy::ERROR,
        );
        $handle       = new JetStreamConsumeHandle($acker, $options);
        $handle->offer(new JetStreamMessage('s', 'm1', [], 'r1', 2));
        $handle->offer(new JetStreamMessage('s', 'm2', [], 'r2', 2));

        // Act
        $first = $handle->next(100);

        // Assert
        self::assertSame('m1', $first?->getPayload());
        self::expectException(JetStreamSlowConsumerException::class);
        $handle->next(100);
    }

    public function testBackpressureDropNewSkipsMessage(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $options      = new PullConsumeOptions(
            batch: 2,
            expiresMs: 1000,
            maxInFlightMessages: 1,
            maxInFlightBytes: 1024,
            policy: SlowConsumerPolicy::DROP_NEW,
        );
        $handle       = new JetStreamConsumeHandle($acker, $options);
        $first        = new JetStreamMessage('s', 'm1', [], 'r1', 2);
        $second       = new JetStreamMessage('s', 'm2', [], 'r2', 2);
        $handle->offer($first);
        $handle->offer($second);
        $handle->completeProducer();

        // Act
        $firstOut  = $handle->next(100);
        $secondOut = $handle->next(100);

        // Assert
        self::assertSame('m1', $firstOut?->getPayload());
        self::assertNull($secondOut);
    }

    public function testAckReleasesInFlightAndAllowsNextMessage(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $options      = new PullConsumeOptions(
            batch: 2,
            expiresMs: 1000,
            maxInFlightMessages: 1,
            maxInFlightBytes: 1024,
            policy: SlowConsumerPolicy::ERROR,
        );
        $handle       = new JetStreamConsumeHandle($acker, $options);
        $first        = new JetStreamMessage('s', 'm1', [], 'r1', 2);
        $second       = new JetStreamMessage('s', 'm2', [], 'r2', 2);
        $handle->offer($first);
        $handle->offer($second);
        $handle->completeProducer();

        // Act
        $firstOut = $handle->next(100);
        $acker->ack($firstOut);
        $secondOut = $handle->next(100);

        // Assert
        self::assertSame('m2', $secondOut?->getPayload());
    }

    public function testBackpressureByBytesThrows(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $options      = new PullConsumeOptions(
            batch: 2,
            expiresMs: 1000,
            maxInFlightMessages: 10,
            maxInFlightBytes: 3,
            policy: SlowConsumerPolicy::ERROR,
        );
        $handle       = new JetStreamConsumeHandle($acker, $options);
        $handle->offer(new JetStreamMessage('s', 'm1', [], 'r1', 2));
        $handle->offer(new JetStreamMessage('s', 'm2', [], 'r2', 2));

        // Act
        $first = $handle->next(100);

        // Assert
        self::assertSame('m1', $first?->getPayload());
        self::expectException(JetStreamSlowConsumerException::class);
        $handle->next(100);
    }
}
