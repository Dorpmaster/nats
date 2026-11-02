<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

class JetStreamApiException extends \RuntimeException
{
    public function __construct(
        private readonly int $apiCode,
        private readonly string $description,
        \Throwable|null $previous = null,
        private readonly int|null $errorCode = null,
    ) {
        parent::__construct($description, $apiCode, $previous);
    }

    public function getErrorCode(): int|null
    {
        return $this->errorCode;
    }

    public function getApiCode(): int
    {
        return $this->apiCode;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
