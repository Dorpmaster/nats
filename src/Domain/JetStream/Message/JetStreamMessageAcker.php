<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;

final class JetStreamMessageAcker implements JetStreamMessageAckerInterface
{
    /** @var array<int, AckObserverInterface> */
    private array $observersByMessageId = [];

    public function __construct(private JetStreamMessageAcknowledgerInterface $acknowledger)
    {
    }

    public function observe(JetStreamMessageInterface $message, AckObserverInterface $observer): void
    {
        $this->observersByMessageId[spl_object_id($message)] = $observer;
    }

    public function ack(JetStreamMessageInterface $message): void
    {
        $this->publishAckPayload($message, '+ACK');
    }

    public function nak(JetStreamMessageInterface $message, int|null $delayMs = null): void
    {
        if ($delayMs === null) {
            $this->publishAckPayload($message, '-NAK');
            return;
        }

        $this->publishAckPayload($message, sprintf('-NAK %d', $delayMs * 1_000_000));
    }

    public function term(JetStreamMessageInterface $message): void
    {
        $this->publishAckPayload($message, '+TERM');
    }

    public function inProgress(JetStreamMessageInterface $message): void
    {
        $this->publishAckPayload($message, '+WPI');
    }

    private function publishAckPayload(JetStreamMessageInterface $message, string $payload): void
    {
        $replyTo = $message->getReplyTo();
        if ($replyTo === null || $replyTo === '') {
            throw new JetStreamApiException(500, 'Missing reply subject for ack');
        }

        $this->acknowledger->acknowledge($replyTo, $payload);

        if (in_array($payload, ['+ACK', '-NAK', '+TERM'], true)) {
            $this->notifyAcknowledged($message);
        }
    }

    private function notifyAcknowledged(JetStreamMessageInterface $message): void
    {
        $id       = spl_object_id($message);
        $observer = $this->observersByMessageId[$id] ?? null;
        if ($observer === null) {
            return;
        }

        unset($this->observersByMessageId[$id]);
        $observer->onMessageAcknowledged($message);
    }
}
