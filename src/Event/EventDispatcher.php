<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Event;

use Closure;
use Dorpmaster\Nats\Domain\Event\EventDispatcherInterface;
use InvalidArgumentException;
use Revolt\EventLoop;

final class EventDispatcher implements EventDispatcherInterface
{
    private array $callbacks = [];
    private string $nextId = 'a';


    public function dispatch(string $eventName, mixed $payload = null): void
    {
        if (empty($eventName)) {
            throw new InvalidArgumentException('Event name cannot be empty string');
        }

        if (!array_key_exists($eventName, $this->callbacks)) {
            return;
        }

        if (!is_array($this->callbacks[$eventName])) {
            return;
        }

        foreach ($this->callbacks[$eventName] as $callback) {
            if (!$callback instanceof Closure) {
                continue;
            }

            EventLoop::queue(static fn () => $callback($eventName, $payload));
        }
    }

    public function subscribe(string $eventName, Closure $callback): string
    {
        if (empty($eventName)) {
            throw new InvalidArgumentException('Event name cannot be empty string');
        }

        $id = $this->nextId++;
        if (!array_key_exists($eventName, $this->callbacks)) {
            $this->callbacks[$eventName] = [
                $id => $callback,
            ];

            return $id;
        }

        $this->callbacks[$eventName][$id] = $callback;

        return $id;
    }

    public function unsubscribe(string $subscriptionId): void
    {
        foreach ($this->callbacks as $eventName => $items) {
            if (!is_array($items)) {
                continue;
            }

            if (!array_key_exists($subscriptionId, $items)) {
                continue;
            }

            unset($this->callbacks[$eventName][$subscriptionId]);

            return;
        }
    }

    public function clear(?string $eventName = null): void
    {
        if ($eventName === null) {
            $this->callbacks = [];

            return;
        }

        if (array_key_exists($eventName, $this->callbacks)) {
            $this->callbacks[$eventName] = [];
        }
    }
}
