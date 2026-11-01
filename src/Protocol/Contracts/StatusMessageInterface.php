<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

use Dorpmaster\Nats\Protocol\MessageStatus;

/** Optional capability; existing message implementations need not implement it. */
interface StatusMessageInterface
{
    public function getStatus(): MessageStatus|null;
}
