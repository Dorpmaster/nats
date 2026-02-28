<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

interface SubMessageInterface
{
    public function getSubject(): string;

    public function getQueueGroup(): string|null;

    public function getSid(): string;
}
