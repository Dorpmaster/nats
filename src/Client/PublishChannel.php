<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Closure;
use Dorpmaster\Nats\Client\WriteBuffer\RequestLifetime;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Dorpmaster\Nats\Domain\Client\PublishChannelInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Throwable;

/** @internal Client-owned inbox; per-frame lifetimes never enter reconnect replay. */
final class PublishChannel implements PublishChannelInterface
{
    private bool $closed                = false;
    private string|null $sid            = null;
    private string|null $cancellationId = null;
    private RequestLifetime $inbox;
    private DeferredCancellation $sendStop;
    private DeferredCancellation $closedCancellation;

    public function __construct(private Cancellation|null $scope, Closure $subscribe, private Closure $enqueue, private Closure $unsubscribe, Closure $onMessage, private Closure $onFailure)
    {
        $this->sendStop           = new DeferredCancellation();
        $this->closedCancellation = new DeferredCancellation();
        $this->inbox              = new RequestLifetime(fn (Throwable $error) => $this->fail($error), $scope);
        try {
            $scope->throwIfRequested();
            $this->cancellationId = $scope->subscribe(fn (CancelledException $error) => $this->fail($error));
            $this->sid            = $subscribe(function ($message) use ($onMessage): void {
                if (!$this->closed) {
                    $onMessage($message);
                }
            }, $this->inbox);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function sendOnce(PubMessageInterface|HPubMessageInterface $message, Closure $onAttempt, bool $control = false): void
    {
        if ($this->closed) {
            throw new ConnectionBoundRequestException(false, new ConnectionException('Publish channel is closed'));
        }
        // Stopping outbound work includes EOB/ping; the inbox owns a separate lifetime.
        $scope    = new CompositeCancellation($this->scope, $this->closedCancellation->getCancellation(), $this->sendStop->getCancellation());
        $deferred = new DeferredFuture();
        $lifetime = null;
        $fail     = static function (Throwable $error) use ($deferred): void {
            if (!$deferred->isComplete()) {
                $deferred->error($error);
            }
        };
        $lifetime = new RequestLifetime(function (Throwable $error) use ($fail, &$lifetime): void {
            $failure = new ConnectionBoundRequestException($lifetime->wasAttempted(), $error);
            $fail($failure);
            $this->fail($failure);
        }, $scope, static function () use ($deferred): void {
            if (!$deferred->isComplete()) {
                $deferred->complete();
            }
        }, $onAttempt);
        $cid      = null;
        try {
            $scope->throwIfRequested();
            $cid = $scope->subscribe(static function (CancelledException $error) use ($lifetime, $fail): void {
                $lifetime->finish();
                $fail(new ConnectionBoundRequestException($lifetime->wasAttempted(), $error));
            });
            ($this->enqueue)($message, $lifetime);
            $deferred->getFuture()->await();
        } catch (ConnectionBoundRequestException $error) {
            $deferred->getFuture()->ignore();
            throw $error;
        } catch (Throwable $error) {
            // No future waiter exists if enqueue itself throws.
            $deferred->getFuture()->ignore();
            throw new ConnectionBoundRequestException($lifetime->wasAttempted(), $error);
        } finally {
            if ($cid !== null) {
                $scope->unsubscribe($cid);
            }
            $lifetime->finish();
        }
    }

    public function stopSending(): void
    {
        $this->sendStop->cancel();
    }

    private function fail(Throwable $error): void
    {
        if ($this->closed) {
            return;
        }
        try {
            ($this->onFailure)($error);
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closedCancellation->cancel();
        $this->stopSending();
        if ($this->cancellationId !== null) {
            $this->scope->unsubscribe($this->cancellationId);
            $this->cancellationId = null;
        }
        $this->inbox->finish();
        $this->scope = null;
        if ($this->sid !== null) {
            try {
                ($this->unsubscribe)($this->sid);
            } catch (Throwable) {
                // The original operation result must survive a cleanup write failure.
            }
            $this->sid = null;
        }
    }
}
