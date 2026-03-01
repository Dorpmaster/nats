<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Pull;

use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcker;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamConsumeHandle;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamConsumeLoop;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamFetchResult;
use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use PHPUnit\Framework\TestCase;

final class JetStreamConsumeLoopTest extends TestCase
{
    public function testLoopDeliversFetchedMessages(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $handle       = new JetStreamConsumeHandle($acker, new PullConsumeOptions(batch: 2, expiresMs: 1000));
        $loop         = new JetStreamConsumeLoop();
        $calls        = 0;
        $loop->start($handle, function () use (&$calls, $acker, $handle): JetStreamFetchResult {
            $calls++;
            if ($calls === 1) {
                return new JetStreamFetchResult([
                    new JetStreamMessage('s', 'm1', [], 'r1', 2),
                    new JetStreamMessage('s', 'm2', [], 'r2', 2),
                ], $acker);
            }

            $handle->stop();
            return new JetStreamFetchResult([], $acker);
        });

        // Act
        $first  = $handle->next(500);
        $second = $handle->next(500);
        $handle->stop();
        $none = $handle->next(100);

        // Assert
        self::assertSame('m1', $first?->getPayload());
        self::assertSame('m2', $second?->getPayload());
        self::assertNull($none);
    }

    public function testDrainStopsAfterQueueIsEmpty(): void
    {
        // Arrange
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $acker        = new JetStreamMessageAcker($acknowledger);
        $handle       = new JetStreamConsumeHandle($acker, new PullConsumeOptions(batch: 1, expiresMs: 1000));
        $loop         = new JetStreamConsumeLoop();
        $calls        = 0;
        $loop->start($handle, function () use (&$calls, $acker, $handle): JetStreamFetchResult {
            $calls++;
            if ($calls === 1) {
                return new JetStreamFetchResult([
                    new JetStreamMessage('s', 'm1', [], 'r1', 2),
                ], $acker);
            }

            $handle->stop();
            return new JetStreamFetchResult([], $acker);
        });

        // Act
        $first = $handle->next(500);
        $handle->drain(500);
        $none = $handle->next(100);

        // Assert
        self::assertSame('m1', $first?->getPayload());
        self::assertNull($none);
    }
}
