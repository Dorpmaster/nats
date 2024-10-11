<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Closure;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;

final class SubscriptionStorage implements SubscriptionStorageInterface
{
    private array $storage = [];

    public function add(string $sid, Closure $closure): void
    {
        $this->storage[$sid] = $closure;
    }

    public function get(string $sid): Closure|null
    {
        return $this->storage[$sid] ?? null;
    }

    public function remove(string $sid): void
    {
        if (array_key_exists($sid, $this->storage) === true) {
            unset($this->storage[$sid]);
        }
    }
}
