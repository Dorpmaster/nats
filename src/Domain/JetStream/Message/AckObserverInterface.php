<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

interface AckObserverInterface
{
    public function onMessageAcknowledged(JetStreamMessageInterface $message): void;
}
