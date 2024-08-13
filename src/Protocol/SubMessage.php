<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\SubMessageInterface;
use Dorpmaster\Nats\Protocol\Internal\IsSidCorrect;
use Dorpmaster\Nats\Protocol\Internal\IsSubjectCorrect;
use InvalidArgumentException;

final readonly class SubMessage implements NatsProtocolMessageInterface, SubMessageInterface
{
    use IsSubjectCorrect;
    use IsSidCorrect;

    public function __construct(
        private string      $subject,
        private string      $sid,
        private string|null $queueGroup = null,
    )
    {
        if (!$this->isSubjectCorrect($this->subject)) {
            throw new InvalidArgumentException(sprintf('Invalid Subject: %s', $this->subject));
        }

        if (!$this->isSidCorrect($this->sid)) {
            throw new InvalidArgumentException(sprintf('Invalid SID: %s', $this->sid));
        }

        if (($this->queueGroup !== null) && !$this->isSidCorrect($this->queueGroup)) {
            throw new InvalidArgumentException(sprintf('Invalid Queue Group: %s', $this->queueGroup));
        }
    }

    public function __toString(): string
    {
        if ($this->queueGroup === null) {
            return sprintf(
                '%s %s %s%s',
                $this->getType()->value,
                $this->getSubject(),
                $this->getSid(),
                NatsProtocolMessageInterface::DELIMITER,
            );
        };

        return sprintf(
            '%s %s %s %s%s',
            $this->getType()->value,
            $this->getSubject(),
            $this->getQueueGroup(),
            $this->getSid(),
            NatsProtocolMessageInterface::DELIMITER,
        );
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::SUB;
    }

    public function getQueueGroup(): string|null
    {
        return $this->queueGroup;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getSid(): string
    {
        return $this->sid;
    }

    public function getPayload(): string
    {
        return '';
    }
}
