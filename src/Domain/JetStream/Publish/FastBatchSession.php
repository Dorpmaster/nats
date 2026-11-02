<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredFuture;
use Amp\DeferredCancellation;
use Revolt\EventLoop;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundPublishClientInterface;
use Dorpmaster\Nats\Domain\Client\PublishChannelInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\FastBatchException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;
use Dorpmaster\Nats\Domain\JetStream\Internal\FastBatchMessage;
use Dorpmaster\Nats\Domain\JetStream\Internal\FastBatchResponse;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Model\FastBatchAck;
use Dorpmaster\Nats\Domain\JetStream\Model\FastBatchProgress;
use Dorpmaster\Nats\Domain\JetStream\Model\FastBatchResult;
use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use InvalidArgumentException;
use LogicException;
use SplQueue;
use Throwable;

/** Streaming gap=fail state machine; retains sizes/sequences, never sent payloads. */
final class FastBatchSession implements FastBatchSessionInterface
{
    private PublishChannelInterface|null $channel = null;
    private DeferredFuture $changed;
    private SplQueue $pending;
    private int $bytes                   = 0;
    private string $sent                 = '0';
    private string $acknowledged         = '0';
    private int $window                  = 1;
    private bool $initial                = false;
    private bool $busy                   = false;
    private bool $commitRequested        = false;
    private bool $commitAttempted        = false;
    private bool $cancelled              = false;
    private Throwable|null $stopReason   = null;
    private string|null $problemSequence = null;
    private string|null $knownRejected   = null;
    private FastBatchResult|null $result = null;
    private string|null $cancellationId  = null;
    private string $base;

    /** Admission cannot be bypassed by direct construction or this factory. */
    public static function open(\Dorpmaster\Nats\Domain\Client\ClientInterface $client, string $stream, array $subjects, FastBatchOptions|null $options = null, mixed $timeoutMs = null): self
    {
        $started = hrtime(true);
        \Dorpmaster\Nats\Domain\JetStream\Internal\ResourceName::validate($stream, 'stream');
        if ($subjects === [] || !array_is_list($subjects)) {
            throw new \InvalidArgumentException('Invalid subjects: expected a nonempty list');
        }
        foreach ($subjects as $subject) {
            if (!is_string($subject)) {
                throw new \InvalidArgumentException('Invalid subjects: expected strings');
            }
            \Dorpmaster\Nats\Domain\JetStream\Internal\FastBatchMessage::subject($subject);
        }
        $timeout = $timeoutMs ?? 30000;
        if (!is_int($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('Invalid timeoutMs: expected positive milliseconds');
        }
        if (!$client instanceof \Dorpmaster\Nats\Domain\Client\ConnectionBoundPublishClientInterface) {
            throw new JetStreamApiException(0, 'Fast batch requires ConnectionBoundPublishClientInterface');
        }
        $capabilities = \Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities::forClient($client);
        $feature      = \Dorpmaster\Nats\Domain\JetStream\Capability\Feature::FAST_BATCH_PUBLISH;
        if ($capabilities->check($feature)->support !== \Dorpmaster\Nats\Domain\JetStream\Capability\Support::SUPPORTED || $capabilities->server->connectionId === null) {
            throw new JetStreamApiException(0, 'Fast batch support is not established on the current connection');
        }
        $options ??= new FastBatchOptions();
        $deadline = $started + $timeout * 1_000_000;
        $scope    = new \Amp\CompositeCancellation($options->cancellation ?? new \Amp\NullCancellation(), new \Amp\TimeoutCancellation(max(0.000001, ($deadline - hrtime(true)) / 1_000_000_000)));
        try {
            (new AtomicBatchTarget($client))->verify($stream, $subjects, $feature, $capabilities->server->connectionId, $deadline, new AtomicBatchOptions(cancellation: $scope), $capabilities->server->serverId);
            return new self($client, $stream, $subjects, $options, $deadline, $scope, bin2hex(random_bytes(16)), $capabilities->server->connectionId);
        } catch (JetStreamApiException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new JetStreamApiException(0, 'Fast batch target confirmation failed', $error);
        }
    }

    /** @param non-empty-list<string> $subjects */
    private function __construct(ConnectionBoundPublishClientInterface $client, private string $stream, private array $subjects, private FastBatchOptions $options, private float $deadline, private Cancellation|null $scope, private string $id, int $connectionId)
    {
        $this->changed = new DeferredFuture();
        $this->pending = new SplQueue();
        $this->base    = '_INBOX.FAST.' . $id;
        try {
            $this->beforeWrite();
            $this->channel        = $client->openPublishChannelOnConnection($this->base . '.>', fn ($message) => $this->receive($message), fn (Throwable $error) => $this->terminate($error), $connectionId, $scope);
            $this->cancellationId = $scope->subscribe(fn (CancelledException $error) => $this->terminate($error));
        } catch (Throwable $error) {
            $this->terminate($error);
            throw new FastBatchException($this->result, $error);
        }
    }

    public function getProgress(): FastBatchProgress
    {
        return new FastBatchProgress($this->stream, $this->id, $this->sent, $this->acknowledged, $this->window, $this->pending->count(), $this->bytes);
    }
    public function getResult(): FastBatchResult|null
    {
        return $this->result;
    }
    public function publish(string $subject, string $payload, HeaderBugInterface|array $headers = []): FastBatchProgress
    {
        $this->enter();
        try {
            $this->sendData($subject, $payload, $headers, false);
            $this->ensureRunning();
            return $this->getProgress();
        } finally {
            $this->busy = false;
        }
    }
    public function commit(string $subject, string $payload, HeaderBugInterface|array $headers = []): FastBatchResult
    {
        $this->enter();
        try {
            $this->sendData($subject, $payload, $headers, true);
            return $this->awaitFinal();
        } finally {
            $this->busy = false;
        }
    }
    public function finish(): FastBatchResult
    {
        $this->enter();
        try {
            if ($this->sent === '0') {
                throw new LogicException('A fast batch needs at least one message before EOB');
            }
            $this->commitRequested = true;
            $frame                 = $this->frame($this->subjects[0], '', [], Decimal::increment($this->sent), 3);
            try {
                $this->channel->sendOnce($frame, function (): void {
                    $this->beforeWrite();
                    $this->commitAttempted = true;
                }, true);
            } catch (Throwable $error) {
                if ($this->result === null && $this->stopReason === null) {
                    $this->terminate($error);
                }
            }
            return $this->awaitFinal();
        } finally {
            $this->busy = false;
        }
    }
    public function cancel(): FastBatchResult
    {
        if ($this->result === null) {
            $this->cancelled = true;
            $this->terminate(new JetStreamApiException(0, 'Fast batch cancelled by caller'));
        }
        return $this->result;
    }
    public function awaitAcknowledged(mixed $sequence = null): FastBatchProgress
    {
        $this->enter();
        try {
            if ($sequence !== null && !is_int($sequence) && !is_string($sequence)) {
                throw new InvalidArgumentException('Invalid sequence: expected integer or decimal string');
            }
            $target = $sequence === null ? $this->sent : Decimal::parse((string) $sequence, Decimal::UINT64_MAX);
            if (Decimal::compare($target, $this->sent) > 0) {
                throw new InvalidArgumentException('Cannot await a sequence that has not been sent');
            }
            $this->wait(fn (): bool => Decimal::compare($this->acknowledged, $target) >= 0, true);
            $this->ensureRunning();
            return $this->getProgress();
        } finally {
            $this->busy = false;
        }
    }

    private function enter(): void
    {
        if ($this->busy) {
            throw new LogicException('Concurrent operations on one fast batch session are not supported');
        }
        $this->ensureRunning();
        $this->busy = true;
    }
    private function checkDeadline(): void
    {
        if ($this->result === null && hrtime(true) >= $this->deadline) {
            $this->terminate(new JetStreamTimeoutException('Fast batch response deadline expired'));
        }
    }
    private function beforeWrite(): void
    {
        $this->checkDeadline();
        if ($this->result !== null) {
            throw new FastBatchException($this->result, $this->result->getCause() ?? new LogicException('Fast batch already completed'));
        }
        if ($this->stopReason !== null) {
            throw $this->stopReason;
        }
        $this->scope?->throwIfRequested();
    }
    private function ensureRunning(): void
    {
        $this->checkDeadline();
        if ($this->result !== null) {
            if (!$this->result->isComplete()) {
                throw new FastBatchException($this->result, $this->result->getCause());
            }
            throw new LogicException('Fast batch session has already completed');
        }
        if ($this->stopReason !== null) {
            $this->awaitFinal();
        }
    }
    private function frame(string $subject, string $payload, HeaderBugInterface|array $headers, string $sequence, int $operation): \Dorpmaster\Nats\Domain\JetStream\Internal\AtomicBatchFrame
    {
        if (!in_array($subject, $this->subjects, true)) {
            throw new InvalidArgumentException('Invalid subject: not declared when opening fast batch');
        }
        $reply = $this->base . '.' . $this->options->initialFlow . '.fail.' . $sequence . '.' . $operation . '.$FI';
        $frame = FastBatchMessage::frame($this->stream, $subject, $payload, $headers, $reply);
        if (strlen((string) $frame) > $this->options->maxMessageBytes) {
            throw new InvalidArgumentException('Message exceeds maxMessageBytes including its command and headers');
        }
        return $frame;
    }
    private function sendData(string $subject, string $payload, HeaderBugInterface|array $headers, bool $commit): void
    {
        $sequence  = Decimal::increment($this->sent);
        $operation = $commit ? 2 : ($sequence === '1' ? 0 : 1);
        $frame     = $this->frame($subject, $payload, $headers, $sequence, $operation);
        $bytes     = strlen((string) $frame);
        $this->wait(fn (): bool => $this->pending->count() < $this->window * $this->options->maxInflightWindows && $this->bytes + $bytes <= $this->options->maxBufferedBytes, true);
        $this->ensureRunning();
        $this->pending->enqueue(['sequence' => $sequence, 'bytes' => $bytes]);
        $this->bytes          += $bytes;
        $this->commitRequested = $commit;
        try {
            $this->channel->sendOnce($frame, function () use ($sequence, $commit): void {
                $this->beforeWrite();
                $this->sent = $sequence;
                if ($commit) {
                    $this->commitAttempted = true;
                }
            });
        } catch (Throwable $error) {
            if ($this->result === null && $this->stopReason === null) {
                $this->terminate($error);
            }
        }
        if (!$commit && $this->result === null) {
            $this->wait(fn (): bool => $this->initial, true);
        }
        if (!$commit) {
            $this->ensureRunning();
        }
    }

    private function wait(\Closure $predicate, bool $ping): void
    {
        $token  = $this->acknowledged . ':' . $this->window . ':' . (int) $this->initial;
        $wakeAt = hrtime(true) + $this->options->ackTimeoutMs * 1_000_000;
        $pinged = false;
        while (!$predicate() && $this->result === null) {
            if ($this->stopReason !== null && $ping) {
                $this->awaitFinal();
            }
            $limit     = min($this->deadline, $ping ? $wakeAt : $this->deadline);
            $remaining = ($limit - hrtime(true)) / 1_000_000_000;
            if ($remaining <= 0) {
                if ($ping && !$pinged && !$this->commitAttempted && $this->stopReason === null && $this->sent !== '0' && hrtime(true) < $this->deadline) {
                    try {
                        $this->channel->sendOnce($this->frame($this->subjects[0], '', [], $this->sent, 4), function (): void {
                            $this->beforeWrite();
                        }, true);
                    } catch (Throwable $error) {
                        if ($this->result === null && $this->stopReason === null) {
                            $this->terminate($error);
                        }
                        break;
                    }
                    $pinged = true;
                    $wakeAt = hrtime(true) + $this->options->pingTimeoutMs * 1_000_000;
                    continue;
                }
                $this->terminate(new JetStreamTimeoutException('Fast batch response deadline expired'));
                break;
            }
            $localTimeout = new DeferredCancellation();
            $timer        = EventLoop::delay($remaining, static fn () => $localTimeout->cancel());
            try {
                $this->changed->getFuture()->await(new CompositeCancellation($this->scope, $localTimeout->getCancellation()));
            } catch (CancelledException $error) {
                if ($this->scope?->isRequested()) {
                    $this->terminate($error);
                }
            } finally {
                EventLoop::cancel($timer);
            }
            $current = $this->acknowledged . ':' . $this->window . ':' . (int) $this->initial;
            if ($current !== $token) {
                $token  = $current;
                $pinged = false;
                $wakeAt = hrtime(true) + $this->options->ackTimeoutMs * 1_000_000;
            }
        }
    }
    private function awaitFinal(): FastBatchResult
    {
        $this->wait(fn (): bool => $this->result !== null, false);
        if (!$this->result->isComplete()) {
            throw new FastBatchException($this->result, $this->result->getCause());
        }
        return $this->result;
    }
    private function notify(): void
    {
        $old           = $this->changed;
        $this->changed = new DeferredFuture();
        $old->complete();
    }
    private function confirm(string $sequence): void
    {
        $this->acknowledged = $sequence;
        while (!$this->pending->isEmpty() && Decimal::compare($this->pending->bottom()['sequence'], $sequence) <= 0) {
            $this->bytes -= $this->pending->dequeue()['bytes'];
        }
    }
    private function receive(MsgMessageInterface|HMsgMessageInterface $message): void
    {
        if ($this->result !== null || !str_starts_with($message->getSubject(), $this->base . '.')) {
            return;
        }
        try {
            $response = FastBatchResponse::parse($message);
            if ($response->kind === 'ack') {
                if ($response->window > $this->options->initialFlow || Decimal::compare($response->sequence, $this->sent) > 0 || $this->sent === '0') {
                    throw new JetStreamApiException(0, 'Invalid fast batch ACK window or sequence');
                }
                if (Decimal::compare($response->sequence, $this->acknowledged) >= 0) {
                    $this->initial = true;
                    $this->window  = $response->window;
                    $this->confirm($response->sequence);
                    $this->notify();
                }
                return;
            }
            if ($response->kind === 'gap' || $response->kind === 'err' || $response->kind === 'error') {
                if (($response->kind === 'err' && Decimal::compare($response->sequence, $this->sent) > 0) || ($response->kind === 'gap' && Decimal::compare($response->sequence, Decimal::increment($this->sent)) > 0)) {
                    throw new JetStreamApiException(0, 'Fast batch error/gap reports an unsent sequence');
                }
                $this->problemSequence ??= $response->kind === 'gap' ? $response->gapStart : $response->sequence;
                $this->stopReason      ??= $response->error ?? new JetStreamApiException(0, 'Fast batch gap');
                $error                   = $response->error;
                $specific                = $error !== null && $error->getApiCode() >= 400 && $error->getApiCode() < 500 && $error->getApiCode() !== 408 && ($error->getErrorCode() ?? 0) >= 10000;
                $parts                   = explode('.', $message->getSubject());
                $replyOperation          = $parts[count($parts) - 2] ?? null;
                $replySequence           = $parts[count($parts) - 3] ?? null;
                if ($specific && $replyOperation !== '4' && $replyOperation !== '3') {
                    $this->knownRejected     = $response->sequence ?? $replySequence;
                    $this->problemSequence ??= $this->knownRejected;
                }
                if ($response->kind === 'error' && $specific && !$this->initial && $this->sent === '1' && $replyOperation !== '4') {
                    $this->terminate($this->stopReason, definite: true);
                    return;
                }
                $this->channel?->stopSending();
                $this->notify();
                return;
            }
            $fields = new MetadataFields($response->raw, 'fast_batch.final');
            $count  = $fields->decimal('count');
            $seq    = $fields->decimal('seq');
            if ($fields->text('stream') !== $this->stream || $fields->text('batch') !== $this->id || $count === null || $seq === null || Decimal::compare($count, $this->sent) > 0 || Decimal::compare($count, $this->acknowledged) < 0) {
                throw new JetStreamApiException(0, 'Invalid final fast batch stream, batch, count or seq');
            }
            $ack = new FastBatchAck($this->stream, $this->id, $count, $seq, $response->raw);
            $this->confirm($count);
            $complete = $this->commitRequested && $this->commitAttempted && $count === $this->sent && $this->stopReason === null;
            if (!$complete && $this->stopReason === null) {
                $this->problemSequence = $count !== $this->sent ? Decimal::increment($count) : null;
                $this->stopReason      = new JetStreamApiException(0, 'Fast batch finalized before full requested completion');
            }
            $this->terminate($this->stopReason, $ack, true, $complete);
        } catch (Throwable $error) {
            $this->terminate($error);
        }
    }
    private function terminate(Throwable|null $cause, FastBatchAck|null $ack = null, bool $definite = false, bool $complete = false): void
    {
        if ($this->result !== null) {
            return;
        }
        $cause           = $this->stopReason ?? $cause;
        $this->cancelled = $this->cancelled || $this->options->cancellation?->isRequested() === true;
        $unknown         = [];
        if (!$definite && Decimal::compare($this->sent, $this->acknowledged) > 0) {
            $from = Decimal::increment($this->acknowledged);
            if ($this->knownRejected !== null && Decimal::compare($this->knownRejected, $from) >= 0 && Decimal::compare($this->knownRejected, $this->sent) <= 0) {
                if ($from !== $this->knownRejected) {
                    $unknown[] = ['from' => $from, 'to' => Decimal::decrement($this->knownRejected)];
                }
                if ($this->knownRejected !== $this->sent) {
                    $unknown[] = ['from' => Decimal::increment($this->knownRejected), 'to' => $this->sent];
                }
            } else {
                $unknown[] = ['from' => $from, 'to' => $this->sent];
            }
        }
        $this->pending = new SplQueue();
        $this->bytes   = 0;
        $this->result  = new FastBatchResult($this->getProgress(), $complete, $unknown !== [] || (!$definite && $this->commitAttempted && $ack === null), $unknown, $ack, $this->problemSequence, $cause, $this->cancelled);
        if ($this->cancellationId !== null && $this->scope !== null) {
            $this->scope->unsubscribe($this->cancellationId);
            $this->cancellationId = null;
        }
        $this->channel?->close();
        $this->channel = null;
        $this->scope   = null;
        $this->notify();
    }
}
