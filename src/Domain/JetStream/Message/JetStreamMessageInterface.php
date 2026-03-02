<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

interface JetStreamMessageInterface
{
    public function getSubject(): string;

    public function getPayload(): string;

    /** @return array<string, string> */
    public function getHeaders(): array;

    public function getSizeBytes(): int;

    public function getReplyTo(): string|null;

    public function getDeliveryCount(): int|null;
}
