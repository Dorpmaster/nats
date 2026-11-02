<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Internal;

use InvalidArgumentException;

trait ValidatePublishTokens
{
    private function validatePublishTokens(string $subject, string|null $replyTo = null): void
    {
        foreach (['Subject' => $subject, 'Reply-To' => $replyTo] as $field => $token) {
            if ($token !== null && preg_match('/\A[^\s\p{Cc}]+\z/u', $token) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid %s: %s', $field, $token));
            }
        }
    }
}
