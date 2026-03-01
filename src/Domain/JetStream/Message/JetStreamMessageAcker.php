<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;

final readonly class JetStreamMessageAcker implements JetStreamMessageAckerInterface
{
    public function __construct(private JetStreamMessageAcknowledgerInterface $acknowledger)
    {
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
    }
}
