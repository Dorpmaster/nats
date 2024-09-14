<?php

declare(strict_types=1);


namespace Dorpmaster\Nats\Protocol\Contracts;

interface ProtocolParserInterface
{
    public function push(string $chunk): void;

    public function cancel(): void;
}
