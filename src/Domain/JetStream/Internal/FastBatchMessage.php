<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use InvalidArgumentException;

/** @internal Validates and freezes one frame before a producer waits for credit. */
final class FastBatchMessage
{
    public static function subject(string $subject): void
    {
        if (preg_match('/\A[^\s\p{Cc}.*>]+(?:\.[^\s\p{Cc}.*>]+)*\z/u', $subject) !== 1) {
            throw new InvalidArgumentException('Invalid subject: expected a concrete subject');
        }
    }

    public static function frame(string $stream, string $subject, string $payload, HeaderBugInterface|array $headers, string $reply): AtomicBatchFrame
    {
        self::subject($subject);
        $values = $headers instanceof HeaderBugInterface ? iterator_to_array($headers) : $headers;
        foreach (array_keys($values) as $name) {
            if (!is_string($name) || preg_match('/\A[!-9;-~]+\z/', $name) !== 1 || str_starts_with(strtolower($name), 'nats-batch-') || in_array(strtolower($name), ['nats-required-api-level', 'nats-expected-stream'], true)) {
                throw new InvalidArgumentException('Invalid headers: malformed or reserved fast batch header');
            }
        }
        $copy = new HeaderBag($values);
        return new AtomicBatchFrame($subject, $payload, iterator_to_array($copy) + ['Nats-Expected-Stream' => $stream, 'Nats-Required-Api-Level' => '4'], $reply);
    }
}
