<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Closure;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;

final class SubscriptionStorage implements SubscriptionStorageInterface
{
    private array $storage = [];

    public function add(string $sid, Closure $closure, string|null $queueGroup = null): void
    {
        $this->storage[$sid] = [
            'closure' => $closure,
            'queueGroup' => $queueGroup,
        ];
    }

    public function get(string $sid): Closure|null
    {
        return $this->storage[$sid]['closure'] ?? null;
    }

    public function getQueueGroup(string $sid): string|null
    {
        return $this->storage[$sid]['queueGroup'] ?? null;
    }

    public function remove(string $sid): void
    {
        if (array_key_exists($sid, $this->storage) === true) {
            unset($this->storage[$sid]);
        }
    }

    /** @return array<string, Closure> */
    public function all(): array
    {
        return array_map(
            static fn (array $subscription): Closure => $subscription['closure'],
            $this->storage,
        );
    }
}
