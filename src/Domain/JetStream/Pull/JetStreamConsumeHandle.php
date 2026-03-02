<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamDrainTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamSlowConsumerException;
use Dorpmaster\Nats\Domain\JetStream\Message\AckObserverInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAckerInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageInterface;

use function Amp\delay;

final class JetStreamConsumeHandle implements AckObserverInterface
{
    private const int INTERNAL_QUEUE_CAPACITY  = 1_000_000;
    public const int MAX_DROP_NEW_REDELIVERIES = 5;

    private Queue $queue;
    /** @var ConcurrentIterator<JetStreamMessageInterface> */
    private ConcurrentIterator $iterator;
    private ConsumeState $state = ConsumeState::RUNNING;
    /** @var array<int, int> */
    private array $inFlightByMessageId = [];
    private int $inFlightMessages      = 0;
    private int $inFlightBytes         = 0;
    private int $queuedMessages        = 0;
    private \Throwable|null $failure   = null;
    private DeferredFuture $deferredStopped;
    private bool $queueCompleted = false;

    public function __construct(
        private readonly JetStreamMessageAckerInterface $acker,
        private readonly PullConsumeOptions $options,
    ) {
        $this->queue           = new Queue(self::INTERNAL_QUEUE_CAPACITY);
        $this->iterator        = $this->queue->iterate();
        $this->deferredStopped = new DeferredFuture();
    }

    public function next(int|null $timeoutMs = null): JetStreamMessage|null
    {
        while (true) {
            $this->throwIfFailed();
            try {
                $hasNext = $timeoutMs === null
                    ? $this->iterator->continue()
                    : $this->iterator->continue(new TimeoutCancellation($timeoutMs / 1000));
            } catch (CancelledException) {
                return null;
            }

            if (!$hasNext) {
                $this->state = ConsumeState::STOPPED;
                $this->completeStopped();
                return null;
            }

            $message              = $this->iterator->getValue();
            $this->queuedMessages = max(0, $this->queuedMessages - 1);

            $nextInFlightMessages = $this->inFlightMessages + 1;
            $nextInFlightBytes    = $this->inFlightBytes + $message->getSizeBytes();
            $isOverflow           = $nextInFlightMessages > $this->options->maxInFlightMessages
                || $nextInFlightBytes > $this->options->maxInFlightBytes;

            if ($isOverflow) {
                if ($this->options->policy === SlowConsumerPolicy::DROP_NEW) {
                    if ($message instanceof JetStreamMessage && $this->shouldAutoTermDroppedMessage($message)) {
                        $this->acker->term($message);
                    }

                    continue;
                }

                $exception     = new JetStreamSlowConsumerException(
                    $this->options->policy,
                    $this->inFlightMessages,
                    $this->inFlightBytes,
                    $this->options->maxInFlightMessages,
                    $this->options->maxInFlightBytes,
                );
                $this->failure = $exception;
                $this->stop();

                throw $exception;
            }

            $id                             = spl_object_id($message);
            $this->inFlightByMessageId[$id] = $message->getSizeBytes();
            $this->inFlightMessages         = $nextInFlightMessages;
            $this->inFlightBytes            = $nextInFlightBytes;
            $this->acker->observe($message, $this);

            if (!$message instanceof JetStreamMessage) {
                $this->release($message);
                continue;
            }

            return $message;
        }
    }

    public function stop(): void
    {
        if ($this->state === ConsumeState::STOPPED) {
            return;
        }

        if ($this->state === ConsumeState::RUNNING) {
            $this->state = ConsumeState::STOPPING;
        }

        $this->completeQueueOnce();
    }

    public function drain(int|null $timeoutMs = null): void
    {
        if ($this->state === ConsumeState::STOPPED) {
            return;
        }

        if ($this->state === ConsumeState::RUNNING) {
            $this->state = ConsumeState::DRAINING;
        }

        $timeoutSeconds = ($timeoutMs ?? 10_000) / 1000;
        $deadline       = microtime(true) + $timeoutSeconds;
        while ($this->queuedMessages > 0 || $this->inFlightMessages > 0) {
            if (microtime(true) >= $deadline) {
                if ($this->state === ConsumeState::DRAINING) {
                    $this->state = ConsumeState::RUNNING;
                }

                throw new JetStreamDrainTimeoutException();
            }

            delay(0.001);
        }

        $this->stop();
        $this->awaitStopped($timeoutMs);
    }

    public function getState(): ConsumeState
    {
        return $this->state;
    }

    public function getAcker(): JetStreamMessageAckerInterface
    {
        return $this->acker;
    }

    public function release(JetStreamMessageInterface $message): void
    {
        $id   = spl_object_id($message);
        $size = $this->inFlightByMessageId[$id] ?? null;
        if ($size === null) {
            return;
        }

        unset($this->inFlightByMessageId[$id]);
        $this->inFlightMessages = max(0, $this->inFlightMessages - 1);
        $this->inFlightBytes    = max(0, $this->inFlightBytes - $size);
        if ($this->state === ConsumeState::DRAINING && $this->queuedMessages === 0 && $this->inFlightMessages === 0) {
            $this->stop();
        }
    }

    public function onMessageAcknowledged(JetStreamMessageInterface $message): void
    {
        $this->release($message);
    }

    public function offer(JetStreamMessageInterface $message): void
    {
        if ($this->state !== ConsumeState::RUNNING && $this->state !== ConsumeState::DRAINING) {
            return;
        }

        if ($this->queueCompleted) {
            return;
        }

        $this->queue->push($message);
        $this->queuedMessages++;
    }

    public function completeProducer(): void
    {
        if ($this->state === ConsumeState::RUNNING || $this->state === ConsumeState::DRAINING) {
            $this->state = ConsumeState::STOPPING;
        }

        $this->completeQueueOnce();
        $this->completeStopped();
    }

    public function fail(\Throwable $exception): void
    {
        $this->failure = $exception;
        $this->stop();
    }

    public function awaitStopped(int|null $timeoutMs = null): void
    {
        if ($this->deferredStopped->isComplete()) {
            return;
        }

        $timeout = ($timeoutMs ?? 10_000) / 1000;
        $this->deferredStopped->getFuture()->await(new TimeoutCancellation($timeout));
    }

    public function isRunning(): bool
    {
        return $this->state === ConsumeState::RUNNING;
    }

    public function isDraining(): bool
    {
        return $this->state === ConsumeState::DRAINING;
    }

    public function getQueuedMessages(): int
    {
        return $this->queuedMessages;
    }

    public function getInFlightMessages(): int
    {
        return $this->inFlightMessages;
    }

    public function getInFlightBytes(): int
    {
        return $this->inFlightBytes;
    }

    private function completeQueueOnce(): void
    {
        if ($this->queueCompleted) {
            return;
        }

        $this->queue->complete();
        $this->queueCompleted = true;
    }

    private function completeStopped(): void
    {
        if ($this->deferredStopped->isComplete()) {
            return;
        }

        $this->deferredStopped->complete();
    }

    private function throwIfFailed(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    private function shouldAutoTermDroppedMessage(JetStreamMessage $message): bool
    {
        $deliveryCount = $message->getDeliveryCount();
        if ($deliveryCount === null) {
            return false;
        }

        return $deliveryCount > self::MAX_DROP_NEW_REDELIVERIES;
    }
}
