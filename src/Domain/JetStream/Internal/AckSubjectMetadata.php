<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

/** @internal */
final class AckSubjectMetadata
{
    public static function deliveryCount(string|null $replyTo): int|null
    {
        if ($replyTo === null || $replyTo === '') {
            return null;
        }

        $tokens = explode('.', $replyTo);
        $size   = count($tokens);
        // v1 has 9 tokens; v2 has domain/account and permits extension tokens after pending.
        $countPosition = match (true) {
            $size === 9 => 4,
            $size >= 11 => 6,
            default => null,
        };
        if ($countPosition === null || $tokens[0] !== '$JS' || $tokens[1] !== 'ACK') {
            return null;
        }

        foreach ($tokens as $token) {
            if (preg_match('/\A[^\s\p{Cc}.*>\/\\\\]+\z/u', $token) !== 1) {
                return null;
            }
        }

        // The five fixed metadata fields must remain decimal tokens in either format.
        for ($index = $countPosition; $index < $countPosition + 5; $index++) {
            if (preg_match('/\A[0-9]+\z/', $tokens[$index]) !== 1) {
                return null;
            }
        }

        $digits  = ltrim($tokens[$countPosition], '0');
        $maximum = (string) PHP_INT_MAX;
        if (
            $digits === '' || strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
        ) {
            return null;
        }

        return (int) $digits;
    }
}
