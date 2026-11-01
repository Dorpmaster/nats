<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use stdClass;

final class ApiError
{
    public static function fromResponse(stdClass $response, int $defaultCode, string $defaultDescription): JetStreamApiException|null
    {
        if (!property_exists($response, 'error')) {
            return null;
        }
        $error = $response->error;
        if (!$error instanceof stdClass) {
            return new JetStreamApiException($defaultCode, 'Invalid JetStream error object');
        }
        return new JetStreamApiException(
            is_int($error->code ?? null) ? $error->code : $defaultCode,
            is_string($error->description ?? null) ? $error->description : $defaultDescription,
            errorCode: is_int($error->err_code ?? null) ? $error->err_code : null,
        );
    }
}
