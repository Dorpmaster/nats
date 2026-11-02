<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneHeadersRequestMessage;
use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\NatsMessageType;

/** An operation-owned HPUB snapshot; no retained HeaderBag reference can alter these bytes. */
final readonly class AtomicBatchFrame implements NatsProtocolMessageInterface, HPubMessageInterface
{
    private JetStreamControlPlaneHeadersRequestMessage $message;
    private string $data;

    /** @param array<string,string|list<string>> $headers */
    public function __construct(string $subject, string $payload, array $headers, string $replyTo)
    {
        $this->message = new JetStreamControlPlaneHeadersRequestMessage($subject, $payload, new HeaderBag($headers), $replyTo);
        $this->data    = (string) $this->message;
    }

    public function __toString(): string
    {
        return $this->data;
    }
    public function getType(): NatsMessageType
    {
        return NatsMessageType::HPUB;
    }
    public function getSubject(): string
    {
        return $this->message->getSubject();
    }
    public function getPayload(): string
    {
        return $this->message->getPayload();
    }
    public function getReplyTo(): string|null
    {
        return $this->message->getReplyTo();
    }
    public function getPayloadSize(): int
    {
        return $this->message->getPayloadSize();
    }
    public function getHeadersSize(): int
    {
        return $this->message->getHeadersSize();
    }
    public function getTotalSize(): int
    {
        return $this->message->getTotalSize();
    }
    public function getHeaders(): HeaderBugInterface
    {
        return clone $this->message->getHeaders();
    }
}
