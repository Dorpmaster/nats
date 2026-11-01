<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client\WriteBuffer;

use Amp\Cancellation;
use Closure;
use Throwable;

/** Shared by the request owner and its queued wire snapshot. */
final class RequestLifetime
{
    private bool $active             = true;
    private bool $attempted          = false;
    private Closure|null $onInactive = null;

    /** @param Closure(Throwable): void $onFailure */
    public function __construct(private Closure|null $onFailure, private Cancellation|null $cancellation)
    {
    }

    public function beginAttempt(): bool
    {
        if (!$this->active || $this->attempted || $this->cancellation?->isRequested()) {
            return false;
        }
        $this->attempted = true;
        return true;
    }

    /** @param Closure(): void $callback */
    public function onInactive(Closure $callback): void
    {
        if (!$this->active) {
            $callback();
            return;
        }
        $this->onInactive = $callback;
    }

    public function finish(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active       = false;
        $this->cancellation = null;
        $callback           = $this->onInactive;
        $this->onInactive   = null;
        $this->onFailure    = null;
        $callback?->__invoke();
    }

    public function fail(Throwable $error): void
    {
        if (!$this->active) {
            return;
        }
        $callback = $this->onFailure;
        $this->finish();
        $callback?->__invoke($error);
    }
}
