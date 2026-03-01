<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

interface JetStreamMessageAcknowledgerInterface
{
    public function acknowledge(string $replyTo, string $payload): void;
}
