<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use InvalidArgumentException;

final class ResourceName
{
    public static function validate(string $name, string $field): void
    {
        if (preg_match('/\A[^\s\p{Cc}.*>\/\\\\]+\z/u', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s name', $field));
        }
    }
}
