<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

interface JetStreamMessageAckerInterface
{
    public function observe(JetStreamMessageInterface $message, AckObserverInterface $observer): void;

    public function ack(JetStreamMessageInterface $message): void;

    public function nak(JetStreamMessageInterface $message, int|null $delayMs = null): void;

    public function term(JetStreamMessageInterface $message): void;

    public function inProgress(JetStreamMessageInterface $message): void;
}
