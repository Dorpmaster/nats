<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

use Dorpmaster\Nats\Domain\JetStream\Model\FastBatchResult;
use Throwable;

final class FastBatchException extends JetStreamApiException
{
    public function __construct(private readonly FastBatchResult $result, Throwable $previous)
    {
        $cause   = $previous instanceof \Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException ? $previous->getPrevious() : $previous;
        $timeout = $cause instanceof \Amp\CancelledException && $cause->getPrevious() instanceof \Amp\TimeoutException;
        parent::__construct($previous instanceof JetStreamApiException ? $previous->getApiCode() : ($timeout ? 408 : 0), 'Fast batch ' . $result->getBatchId() . ': ' . ($previous instanceof JetStreamApiException ? $previous->getDescription() : 'Session stopped; inspect its confirmed and unknown sequences'), $previous, $previous instanceof JetStreamApiException ? $previous->getErrorCode() : null);
    }
    public function getResult(): FastBatchResult
    {
        return $this->result;
    }
}
