<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

use Throwable;

final class ScheduledPublishException extends JetStreamApiException
{
    public function __construct(private readonly string $stream, private readonly string $definitionSubject, private readonly bool $outcomeUnknown, Throwable $previous)
    {
        $cause   = $previous instanceof \Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException ? $previous->getPrevious() : $previous;
        $timeout = $cause instanceof \Amp\CancelledException && $cause->getPrevious() instanceof \Amp\TimeoutException;
        parent::__construct($previous instanceof JetStreamApiException ? $previous->getApiCode() : ($timeout ? 408 : 0), $previous instanceof JetStreamApiException ? $previous->getDescription() : 'Scheduled publication failed; definition outcome may require inspection', $previous, $previous instanceof JetStreamApiException ? $previous->getErrorCode() : null);
    }
    public function getStream(): string
    {
        return $this->stream;
    }
    public function getDefinitionSubject(): string
    {
        return $this->definitionSubject;
    }
    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
}
