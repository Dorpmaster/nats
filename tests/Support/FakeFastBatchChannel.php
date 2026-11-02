<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Closure;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Dorpmaster\Nats\Domain\Client\PublishChannelInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Revolt\EventLoop;

final class FakeFastBatchChannel implements PublishChannelInterface
{
    public array $records    = [];
    public bool $closed      = false;
    public bool $stopped     = false;
    public int $closes       = 0;
    public int $lastFlow     = 0;
    public string $lastReply = '';
    public function __construct(public FakeFastBatchClient $client, public string $inbox, public Closure $onMessage, public Closure $onFailure)
    {
    }
    public function sendOnce(PubMessageInterface|HPubMessageInterface $message, Closure $onAttempt, bool $control = false): void
    {
        if ($this->closed || $this->stopped) {
            throw new ConnectionBoundRequestException(false, new \RuntimeException('Closed controlled channel'));
        }
        $this->client->beforeAttempt?->__invoke();
        $onAttempt();
        $reply           = $message->getReplyTo();
        $this->lastReply = $reply;
        $tokens          = explode('.', $reply);
        $operation       = (int) $tokens[count($tokens) - 2];
        $sequence        = (int) $tokens[count($tokens) - 3];
        $flow            = (int) $tokens[count($tokens) - 5];
        $id              = $tokens[count($tokens) - 6];
        $window          = $this->client->window ?? $flow;
        $this->records[] = ['sequence' => $sequence, 'operation' => $operation, 'message' => $message, 'wire' => (string) $message];
        if ($this->client->onFrame !== null) {
            ($this->client->onFrame)($message, $this);
            return;
        }
        if ($operation === 0) {
            $this->emit('{"type":"ack","seq":0,"msgs":' . $window . '}');
        }
        if ($operation === 4) {
            $this->emit('{"type":"ack","seq":' . $this->lastFlow . ',"msgs":' . $window . '}');
        } elseif ($operation === 2 || $operation === 3) {
            $count = $operation === 3 ? $sequence - 1 : $sequence;
            $this->emit('{"stream":"S","batch":"' . $id . '","count":' . $count . ',"seq":' . $this->client->streamSequence . '}');
        } elseif ($this->client->autoAck && $sequence % $window === 0) {
            $this->lastFlow = $sequence;
            $this->emit('{"type":"ack","seq":' . $sequence . ',"msgs":' . $window . '}');
        }
    }
    public function emit(string $payload, string|null $reply = null, bool $force = false): void
    {
        $reply ??= $this->lastReply;
        EventLoop::defer(function () use ($payload, $reply, $force): void {
            if (!$this->closed || $force) {
                $message = new MsgMessage($reply, '1', $payload);
                if ($this->client->filter === null || ($this->client->filter)($message)) {
                    ($this->onMessage)($message);
                }
            }
        });
    }
    public function stopSending(): void
    {
        $this->stopped = true;
    }
    public function close(): void
    {
        if (!$this->closed) {
            $this->closes++;
            $this->closed = true;
        }
    }
}
