<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\UnSubMessageInterface;
use Dorpmaster\Nats\Protocol\Internal\IsSidCorrect;
use InvalidArgumentException;

final readonly class UnSubMessage implements NatsProtocolMessageInterface, UnSubMessageInterface
{
    use IsSidCorrect;

    public function __construct(
        private string      $sid,
        private int|null $maxMessages = null,
    )
    {
        if (!$this->isSidCorrect($this->sid)) {
            throw new InvalidArgumentException(sprintf('Invalid SID: %s', $this->sid));
        }

        if (($this->maxMessages !== null) && ($this->maxMessages <= 0)) {
            throw new InvalidArgumentException('Max Messages should be greater then 0');
        }
    }

    public function __toString(): string
    {
        if ($this->maxMessages === null) {
            return sprintf(
                '%s %s%s',
                $this->getType()->value,
                $this->getSid(),
                NatsProtocolMessageInterface::DELIMITER,
            );
        };

        return sprintf(
            '%s %s %d%s',
            $this->getType()->value,
            $this->getSid(),
            $this->getMaxMessages(),
            NatsProtocolMessageInterface::DELIMITER,
        );
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::UNSUB;
    }

    public function getMaxMessages(): int|null
    {
        return $this->maxMessages;
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
