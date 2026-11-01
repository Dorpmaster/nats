<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Closure;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundPublishClientInterface;
use Dorpmaster\Nats\Domain\Client\PublishChannelInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;

/** Control-plane stub and independently scheduled flow responses for public API unit tests. */
final class FakeFastBatchClient implements ClientInterface, ConnectionBoundPublishClientInterface, ServerInformationProviderInterface
{
    public int|null $level             = 5;
    public bool $current               = true;
    public bool $enabled               = true;
    public bool $autoAck               = true;
    public int|null $window            = null;
    public string $streamSequence      = '18446744073709551615';
    public array $checks               = [];
    public array $channels             = [];
    public Closure|null $beforeAttempt = null;
    public Closure|null $onFrame       = null;
    public Closure|null $filter        = null;
    public Closure|null $read          = null;
    public function connect(): void
    {
    }
    public function disconnect(): void
    {
    }
    public function waitForTermination(): void
    {
    }
    public function subscribe(string $subject, Closure $closure, string|null $queueGroup = null): string
    {
        throw new \LogicException('Use the owned channel');
    }
    public function unsubscribe(string $sid): void
    {
        throw new \LogicException('Use the owned channel');
    }
    public function publish(PubMessageInterface|HPubMessageInterface $message): void
    {
        throw new \LogicException('No replayable publications');
    }
    public function request(PubMessageInterface|HPubMessageInterface $message, float $timeout = 30): MsgMessageInterface|HMsgMessageInterface
    {
        throw new \LogicException('No replayable requests');
    }
    public function requestOnce(PubMessageInterface|HPubMessageInterface $message, float $timeout = 30): MsgMessageInterface|HMsgMessageInterface
    {
        throw new \LogicException('Connection identity required');
    }
    public function getServerInformation(): ServerInformation
    {
        return new ServerInformation('fake', '2.15.2', true, $this->level, 1, fn (): bool => $this->current);
    }
    public function requestOnceOnConnection(PubMessageInterface|HPubMessageInterface $message, int $connectionId, float $timeout = 30, Cancellation|null $cancellation = null): MsgMessageInterface|HMsgMessageInterface
    {
        $this->checks[] = $message->getSubject();
        $payload        = $this->read?->__invoke($message);
        $payload      ??= AtomicBatchTopologyResponses::payload($message->getSubject(), 'fake');
        $payload      ??= str_contains($message->getSubject(), '.INFO.')
            ? '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_atomic":false,"allow_batched":' . ($this->enabled ? 'true' : 'false') . ',"metadata":{"_nats.level":"5"}}}'
            : '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":1,"streams":["S"]}';
        return new MsgMessage($message->getReplyTo(), '1', $payload);
    }
    public function openPublishChannelOnConnection(string $inbox, Closure $onMessage, Closure $onFailure, int $connectionId, Cancellation|null $cancellation = null): PublishChannelInterface
    {
        $channel          = new FakeFastBatchChannel($this, $inbox, $onMessage, $onFailure);
        $this->channels[] = $channel;
        return $channel;
    }
}
