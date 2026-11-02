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
    public function __construct(private Closure|null $onFailure, private Cancellation|null $cancellation, private Closure|null $onWritten = null, private Closure|null $onAttempt = null)
    {
    }

    public function wasAttempted(): bool
    {
        return $this->attempted;
    }

    public function beginAttempt(): bool
    {
        if (!$this->active || $this->attempted || $this->cancellation?->isRequested()) {
            return false;
        }
        $this->attempted = true;
        $callback        = $this->onAttempt;
        $this->onAttempt = null;
        try {
            $callback?->__invoke();
        } catch (Throwable $error) {
            $this->attempted = false;
            $this->fail($error);
            return false;
        }
        return true;
    }

    public function notifyWritten(): void
    {
        if (!$this->active || $this->onWritten === null) {
            return;
        }
        $callback = $this->onWritten;
        $this->finish();
        $callback();
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
        $this->onWritten    = null;
        $this->onAttempt    = null;
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
