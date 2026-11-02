<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Message\MessageMetadata;
use Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp;
use InvalidArgumentException;

/** @internal */
final class AckSubjectMetadata
{
    public static function parse(string|null $replyTo): MessageMetadata|null
    {
        $tokens = self::tokens($replyTo);
        if ($tokens === null) {
            return null;
        }
        $position = count($tokens) === 9 ? 4 : 6;
        try {
            return new MessageMetadata(
                stream: $tokens[$position - 2],
                consumer: $tokens[$position - 1],
                streamSequence: $tokens[$position + 1],
                consumerSequence: $tokens[$position + 2],
                deliveryCount: $tokens[$position],
                pending: $tokens[$position + 4],
                timestamp: NanoTimestamp::fromUnixNanoseconds($tokens[$position + 3]),
                domain: $position === 4 ? null : ($tokens[2] === '_' ? '' : $tokens[2]),
                accountHash: $position === 4 ? null : $tokens[3],
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function deliveryCount(string|null $replyTo): int|null
    {
        $tokens = self::tokens($replyTo);
        if ($tokens === null) {
            return null;
        }
        $countPosition = count($tokens) === 9 ? 4 : 6;

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

    /** @return list<string>|null */
    private static function tokens(string|null $replyTo): array|null
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

        return $tokens;
    }
}
