<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Closure;

interface SubscriptionStorageInterface
{
    public function add(string $sid, Closure $closure): void;

    public function get(string $sid): Closure|null;

    public function remove(string $sid): void;
}
