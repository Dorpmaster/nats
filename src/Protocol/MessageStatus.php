<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

final readonly class MessageStatus
{
    public function __construct(public int $code, public string $description)
    {
        if ($code < 100 || $code > 999 || preg_match('/[\x00-\x1f\x7f]/', $description)) {
            throw new \InvalidArgumentException('Invalid message status');
        }
    }

    public static function fromHeaderBlock(string $headers): self|null
    {
        $line = explode("\r\n", $headers, 2)[0];
        if (preg_match('/\ANATS\/1\.0 ([0-9]{3})(?: (.*))?\z/', $line, $matches) !== 1) {
            return null;
        }

        return new self((int) $matches[1], $matches[2] ?? '');
    }
}
