<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;

final class SubscriptionIdHelper implements SubscriptionIdHelperInterface
{
    public function generateId(): string
    {
        return self::generateSubscriptionId();
    }

    public static function generateSubscriptionId(): string
    {
        return str_replace('.', '', uniqid(more_entropy: true));
    }
}
