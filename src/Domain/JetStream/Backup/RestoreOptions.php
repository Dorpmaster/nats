<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Backup;

use InvalidArgumentException;

final readonly class RestoreOptions
{
    public function __construct(public int $chunkSize = 131072, public int $maxSourceChunkSize = 1048576)
    {
        if ($chunkSize <= 0 || $chunkSize > 1048576) {
            throw new InvalidArgumentException('Invalid chunkSize: expected 1..1048576 bytes');
        }
        if ($maxSourceChunkSize < $chunkSize || $maxSourceChunkSize > 33554432) {
            throw new InvalidArgumentException('Invalid maxSourceChunkSize: expected chunkSize..33554432 bytes');
        }
    }
}
