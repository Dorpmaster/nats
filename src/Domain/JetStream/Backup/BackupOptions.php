<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Backup;

use InvalidArgumentException;

final readonly class BackupOptions
{
    public function __construct(public bool $includeConsumers = true, public int $chunkSize = 131072, public int $windowSize = 1048576, public bool $checkMessages = false)
    {
        if ($chunkSize < 1024 || $chunkSize > 1048576) {
            throw new InvalidArgumentException('Invalid chunkSize: expected 1024..1048576 bytes');
        }
        if ($windowSize < $chunkSize || $windowSize > 33554432) {
            throw new InvalidArgumentException('Invalid windowSize: expected chunkSize..33554432 bytes');
        }
    }
}
