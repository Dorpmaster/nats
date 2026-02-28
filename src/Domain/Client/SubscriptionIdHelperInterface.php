<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

interface SubscriptionIdHelperInterface
{
    public function generateId(): string;
}
