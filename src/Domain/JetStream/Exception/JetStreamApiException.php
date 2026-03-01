<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

final class JetStreamApiException extends \RuntimeException
{
    public function __construct(
        private readonly int $apiCode,
        private readonly string $description,
        \Throwable|null $previous = null,
    ) {
        parent::__construct($description, $apiCode, $previous);
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
