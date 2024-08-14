<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

interface MsgMessageInterface
{
    public function getSubject(): string;

    public function getReplyTo(): string|null;

    public function getPayloadSize(): int;

    public function getSid(): string;
}
