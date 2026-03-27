<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Closure;

interface SubscriptionStorageInterface
{
    public function add(string $sid, Closure $closure, string|null $queueGroup = null): void;

    public function get(string $sid): Closure|null;

    public function getQueueGroup(string $sid): string|null;

    public function remove(string $sid): void;

    /** @return array<string, Closure> */
    public function all(): array;
}
