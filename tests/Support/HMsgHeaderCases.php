<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

final class HMsgHeaderCases
{
    /** @return iterable<string, array{array<string, list<string>>}> */
    public static function cases(): iterable
    {
        yield 'single NATS version value' => [['X' => ['NATS/1.0']]];
        yield 'embedded NATS text and neighbor' => [['X' => ['prefix NATS/2.0 suffix'], 'Neighbor' => ['retained']]];
        yield 'URL and multiple colons' => [['X' => ['https://example.test/NATS/a:b']]];
        yield 'ordered repeated values' => [['X' => ['NATS/1.0', 'second', 'third']]];
    }
}
