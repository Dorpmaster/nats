<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Internal\IsSidCorrect;
use Dorpmaster\Nats\Protocol\Internal\IsSubjectCorrect;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\WireBodySizeMessageInterface;
use InvalidArgumentException;

final readonly class HMsgMessage implements NatsProtocolMessageInterface, HMsgMessageInterface, StatusMessageInterface, WireBodySizeMessageInterface
{
    use IsSubjectCorrect;
    use IsSidCorrect;

    private int $payloadSize;
    private int $headersSize;
    private int $totalSize;

    public function __construct(
        private string $subject,
        private string $sid,
        private string $payload,
        private HeaderBugInterface $headers,
        private string|null $replyTo = null,
        private MessageStatus|null $status = null,
        private int|null $wireBodySize = null,
    ) {
        if ($this->headers->count() === 0 && $this->status === null) {
            throw new InvalidArgumentException('Headers must not be empty');
        }

        if (!$this->isSubjectCorrect($this->subject)) {
            throw new InvalidArgumentException(sprintf('Invalid Subject: %s', $this->subject));
        }

        if (!$this->isSidCorrect($this->sid)) {
            throw new InvalidArgumentException(sprintf('Invalid SID: %s', $this->sid));
        }

        if (($this->replyTo !== null) && !$this->isSubjectCorrect($this->replyTo)) {
            throw new InvalidArgumentException(sprintf('Invalid Reply-To: %s', $this->replyTo));
        }

        $this->payloadSize = strlen($this->payload);
        // The size of the headers section in bytes including the ␍␊␍␊ delimiter before the payload.
        $this->headersSize = strlen($this->headerBlock()) + 4;
        $this->totalSize   = $this->payloadSize + $this->headersSize;
    }

    public function __toString(): string
    {
        if ($this->replyTo === null) {
            return sprintf(
                '%s %s %s %d %d%s%s%s%s%s%s',
                $this->getType()->value,
                $this->getSubject(),
                $this->getSid(),
                $this->getHeadersSize(),
                $this->getTotalSize(),
                NatsProtocolMessageInterface::DELIMITER,
                $this->headerBlock(),
                NatsProtocolMessageInterface::DELIMITER,
                NatsProtocolMessageInterface::DELIMITER,
                $this->getPayload(),
                NatsProtocolMessageInterface::DELIMITER,
            );
        };

        return sprintf(
            '%s %s %s %s %d %d%s%s%s%s%s%s',
            $this->getType()->value,
            $this->getSubject(),
            $this->getSid(),
            $this->getReplyTo(),
            $this->getHeadersSize(),
            $this->getTotalSize(),
            NatsProtocolMessageInterface::DELIMITER,
            $this->headerBlock(),
            NatsProtocolMessageInterface::DELIMITER,
            NatsProtocolMessageInterface::DELIMITER,
            $this->getPayload(),
            NatsProtocolMessageInterface::DELIMITER,
        );
    }

    public function getWireBodySize(): int
    {
        return $this->wireBodySize ?? $this->getTotalSize();
    }

    public function getStatus(): MessageStatus|null
    {
        return $this->status;
    }

    private function headerBlock(): string
    {
        $headers = (string) $this->headers;
        if ($this->status === null) {
            return $headers;
        }

        return 'NATS/1.0 ' . $this->status->code
            . ($this->status->description === '' ? '' : ' ' . $this->status->description)
            . substr($headers, strlen('NATS/1.0'));
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::HMSG;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getReplyTo(): string|null
    {
        return $this->replyTo;
    }

    public function getPayloadSize(): int
    {
        return $this->payloadSize;
    }

    public function getSid(): string
    {
        return $this->sid;
    }

    public function getHeadersSize(): int
    {
        return $this->headersSize;
    }

    public function getTotalSize(): int
    {
        return $this->totalSize;
    }

    public function getHeaders(): HeaderBugInterface
    {
        return $this->headers;
    }
}
