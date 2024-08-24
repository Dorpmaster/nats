<?php

declare(strict_types=1);


namespace Dorpmaster\Nats\Domain\Event;

use Closure;
use InvalidArgumentException;

interface EventDispatcherInterface
{
    /**
     * @throws InvalidArgumentException
     */
    public function dispatch(string $eventName, mixed $payload): void;

    /**
     * @param string $eventName
     * @param Closure $callback Callback to be invoked when dispatch an event.
     * $callback((string $eventName, mixed $payload)): void
     * @return string Subscription ID.
     *
     * @throws InvalidArgumentException
     */
    public function subscribe(string $eventName, Closure $callback): string;

    /**
     * @throws InvalidArgumentException
     */
    public function unsubscribe(string $subscriptionId): void;

    /**
     * @throws InvalidArgumentException
     */
    public function clear(string|null $eventName = null): void;
}
