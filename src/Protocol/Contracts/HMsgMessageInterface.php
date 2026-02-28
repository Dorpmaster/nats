<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

interface HMsgMessageInterface
{
    public function getSubject(): string;

    public function getReplyTo(): string|null;

    public function getPayloadSize(): int;

    public function getSid(): string;

    public function getHeadersSize(): int;

    public function getTotalSize(): int;

    public function getHeaders(): HeaderBugInterface;
}
