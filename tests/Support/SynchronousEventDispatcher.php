<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Closure;
use Dorpmaster\Nats\Domain\Event\EventDispatcherInterface;
use InvalidArgumentException;

final class SynchronousEventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, array<string, Closure>> */
    private array $callbacks = [];
    private string $nextId   = 'a';

    public function dispatch(string $eventName, mixed $payload): void
    {
        if ($eventName === '') {
            throw new InvalidArgumentException('Event name cannot be empty string');
        }

        foreach ($this->callbacks[$eventName] ?? [] as $callback) {
            $callback($eventName, $payload);
        }
    }

    public function subscribe(string $eventName, Closure $callback): string
    {
        if ($eventName === '') {
            throw new InvalidArgumentException('Event name cannot be empty string');
        }

        $id                               = $this->nextId;
        $this->nextId                     = str_increment($this->nextId);
        $this->callbacks[$eventName][$id] = $callback;

        return $id;
    }

    public function unsubscribe(string $subscriptionId): void
    {
        foreach ($this->callbacks as $eventName => $callbacks) {
            if (!array_key_exists($subscriptionId, $callbacks)) {
                continue;
            }

            unset($this->callbacks[$eventName][$subscriptionId]);

            return;
        }
    }

    public function clear(string|null $eventName = null): void
    {
        if ($eventName === null) {
            $this->callbacks = [];

            return;
        }

        $this->callbacks[$eventName] = [];
    }
}
