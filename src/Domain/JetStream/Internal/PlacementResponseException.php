<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;

/** @internal Preserve the envelope so a tracked caller can authenticate the response type. */
final class PlacementResponseException extends JetStreamApiException
{
    public function __construct(public readonly JsonObject $response, JetStreamApiException $cause)
    {
        parent::__construct($cause->getApiCode(), $cause->getDescription(), $cause, $cause->getErrorCode());
    }
}
