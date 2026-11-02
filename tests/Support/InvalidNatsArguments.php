<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

final class InvalidNatsArguments
{
    /** @return array<string, string> */
    public static function commandTokens(): array
    {
        return [
            'empty' => '',
            'space' => 'bad value',
            'tab' => "bad\tvalue",
            'cr' => "bad\rvalue",
            'lf' => "bad\nvalue",
            'crlf' => "bad\r\nvalue",
            'nul' => "bad\0value",
            'trailing-lf' => "valid\n",
            'leading-lf' => "\nvalid",
            'vertical-tab' => "bad\vvalue",
            'form-feed' => "bad\fvalue",
            'delete' => "bad\x7fvalue",
        ];
    }

    /** @return array<string, string> */
    public static function resourceNames(): array
    {
        return self::commandTokens() + [
            'dot' => 'bad.name',
            'star' => 'bad*name',
            'greater-than' => 'bad>name',
            'slash' => 'bad/name',
            'backslash' => 'bad\\name',
            'unicode-space' => "bad\u{00a0}name",
            'unicode-control' => "bad\u{0085}name",
        ];
    }
}
