<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;

/** Explicit wire evidence and barriers for real-server tests; never a production logger. */
final class FastBatchProbeClient implements ClientInterface, \Dorpmaster\Nats\Domain\Client\ConnectionBoundPublishClientInterface, ServerInformationProviderInterface
{
    public array $records = [];
    public array $checks  = [];
    public function __construct(public Client $inner, public \Closure|null $before = null, public \Closure|null $after = null)
    {
    }
    public function connect(): void
    {
        $this->inner->connect();
    }
    public function disconnect(): void
    {
        $this->inner->disconnect();
    }
    public function waitForTermination(): void
    {
        $this->inner->waitForTermination();
    }
    public function subscribe(string $subject, \Closure $closure, string|null $queueGroup = null): string
    {
        return $this->inner->subscribe($subject, $closure, $queueGroup);
    }
    public function unsubscribe(string $sid): void
    {
        $this->inner->unsubscribe($sid);
    }
    public function publish(PubMessageInterface|HPubMessageInterface $message): void
    {
        throw new \LogicException('Atomic publish must not use ordinary publication');
    }
    public function request(PubMessageInterface|HPubMessageInterface $message, float $timeout = 30): MsgMessageInterface|HMsgMessageInterface
    {
        throw new \LogicException('Atomic publish must not use replayable requests');
    }
    public function requestOnce(PubMessageInterface|HPubMessageInterface $message, float $timeout = 30): MsgMessageInterface|HMsgMessageInterface
    {
        return $this->inner->requestOnce($message, $timeout);
    }
    public function getServerInformation(): ServerInformation
    {
        return $this->inner->getServerInformation();
    }
    public int $dataFrames            = 0;
    public int $commits               = 0;
    public int $pings                 = 0;
    public array $controls            = [];
    public array $attemptedOperations = [];
    public \Closure|null $filter      = null;
    public \Closure|null $writeFilter = null;

    public function requestOnceOnConnection(PubMessageInterface|HPubMessageInterface $message, int $connectionId, float $timeout = 30, Cancellation|null $cancellation = null): MsgMessageInterface|HMsgMessageInterface
    {
        $response = $this->inner->requestOnceOnConnection($message, $connectionId, $timeout, $cancellation);
        if (count($this->checks) < 64) {
            $this->checks[] = ['subject' => $message->getSubject(), 'payload' => $message->getPayload(), 'response' => $response->getPayload()];
        }
        return $response;
    }
    public function openPublishChannelOnConnection(string $inbox, \Closure $onMessage, \Closure $onFailure, int $connectionId, Cancellation|null $cancellation = null): \Dorpmaster\Nats\Domain\Client\PublishChannelInterface
    {
        $channel = $this->inner->openPublishChannelOnConnection($inbox, function ($message) use ($onMessage): void {
            if (count($this->controls) < 64) {
                $this->controls[] = ['subject' => $message->getSubject(), 'payload' => $message->getPayload()];
            }
            $filtered = $this->filter === null ? $message : ($this->filter)($message, $onMessage);
            if ($filtered !== null && $filtered !== false) {
                $onMessage($filtered);
            }
        }, $onFailure, $connectionId, $cancellation);
        return new class ($channel, $this) implements \Dorpmaster\Nats\Domain\Client\PublishChannelInterface {
            public function __construct(private \Dorpmaster\Nats\Domain\Client\PublishChannelInterface $inner, private FastBatchProbeClient $probe)
            {
            }
            public function sendOnce(PubMessageInterface|HPubMessageInterface $message, \Closure $onAttempt, bool $control = false): void
            {
                $parts     = explode('.', $message->getReplyTo());
                $operation = (int) $parts[count($parts) - 2];
                if ($operation === 4) {
                    $this->probe->pings++;
                } elseif ($operation !== 3) {
                    $this->probe->dataFrames++;
                }
                if ($operation === 2 || $operation === 3) {
                    $this->probe->commits++;
                }
                if (count($this->probe->records) < 64) {
                    $this->probe->records[] = ['subject' => $message->getSubject(), 'reply' => $message->getReplyTo(), 'operation' => $operation, 'wire_base64' => base64_encode((string) $message)];
                }
                $actual = $this->probe->writeFilter === null ? $message : ($this->probe->writeFilter)($message);
                if ($actual === false) {
                    // Controlled loss after handoff: the publisher cannot infer whether this frame arrived.
                    $onAttempt();
                    return;
                }
                $this->inner->sendOnce($actual, function () use ($onAttempt, $operation): void {
                    $onAttempt();
                    if (count($this->probe->attemptedOperations) < 64) {
                        $this->probe->attemptedOperations[] = $operation;
                    }
                }, $control);
            }
            public function stopSending(): void
            {
                $this->inner->stopSending();
            }
            public function close(): void
            {
                $this->inner->close();
            }
        };
    }
}
