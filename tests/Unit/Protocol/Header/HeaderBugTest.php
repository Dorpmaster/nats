<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Header;

use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Traversable;

final class HeaderBugTest extends TestCase
{
    public function testNames(): void
    {
        $bag = new HeaderBag([
            'first' => 'Test',
            'second' => ['One', 'Two'],
        ]);

        self::assertEquals(['first', 'second'], $bag->names());
    }

    public function testGetIterator(): void
    {
        $bag = new HeaderBag([
            'first' => 'Test',
            'second' => ['One', 'Two'],
        ]);

        self::assertInstanceOf(Traversable::class, $bag->getIterator());
        foreach ($bag->getIterator() as $key => $value) {
            self::assertIsString($key);
        }
    }

    public function testCount(): void
    {
        $bag = new HeaderBag([
            'first' => 'Test',
            'second' => ['One', 'Two'],
        ]);

        self::assertEquals(2, $bag->count());
    }

    public function testGet(): void
    {
        $bag = new HeaderBag([
            'first' => 'Test',
            'second' => ['One', 'Two'],
        ]);

        self::assertEquals('Test', $bag->get('first'));
        self::assertEquals(['One', 'Two'], $bag->get('second'));
        self::assertNull($bag->get('default'));
        self::assertEquals('default', $bag->get('default', 'default'));
    }

    #[DataProvider('setDataProvider')]
    public function testSet(
        string $key,
        string|array $value,
        string|array $expectedValue,
        string|null $exception = null,
    ): void
    {
        $bag = new HeaderBag();

        if ($exception !== null) {
            self::expectException(InvalidArgumentException::class);
            self::expectExceptionMessage($exception);
        }

        $bag->set($key, $value);

        if ($exception !== null) {
            return;
        }

        self::assertEquals($expectedValue, $bag->get($key));
    }

    #[DataProvider('toStringDataProvider')]
    public function testToString(array $headers, string $expected): void
    {
        $bag = new HeaderBag($headers);

        self::assertSame($expected, (string) $bag);
    }

    public static function setDataProvider(): array
    {
        return [
            'correct-key' => [
                'correct_key',
                'Correct Value',
                'Correct Value',
            ],
            'value-should-be-trimmed' => [
                'correct_key',
                "  Correct Value\t ",
                'Correct Value',
            ],
            'value-with-CRLF' => [
                'correct_key',
                "Correct \r\nValue",
                'Correct Value',
            ],
            'value-with-CR' => [
                'correct_key',
                "Correct \rValue",
                'Correct Value',
            ],
            'value-with-LF' => [
                'correct_key',
                "Correct \nValue",
                'Correct Value',
            ],
            'key-with-:' => [
                'correct:key',
                'Correct Value',
                'Correct Value',
                'Invalid header name: correct:key'
            ],
            'key-with-invalid-chars' => [
                "correct key\t",
                'Correct Value',
                'Correct Value',
                "Invalid header name: correct key\t"
            ],
        ];
    }

    public static function toStringDataProvider(): array
    {
        return [
            'empty' => [
                [],
                'NATS/1.0',
            ],
            'one-header' => [
                ['one' => 'value'],
                "NATS/1.0\r\none: value",
            ],
            'two-headers' => [
                ['one' => 'first', 'two' => 'second'],
                "NATS/1.0\r\none: first\r\ntwo: second",
            ],
            'several-values' => [
                ['one' => 'first', 'two' => ['second', 'third']],
                "NATS/1.0\r\none: first\r\ntwo: second\r\ntwo: third",
            ],
            'complex-values' => [
                [
                    'plain' => 'value',
                    'should-be-trimmed' => " value \t",
                    'CRLF' => "CRLF\r\nvalue",
                    'CR' => "CR \rvalue ",
                    'LF' => " LF \nvalue",

                ],
                "NATS/1.0\r\nplain: value\r\nshould-be-trimmed: value\r\nCRLF: CRLFvalue\r\nCR: CR value\r\nLF: LF value",
            ],
        ];
    }
}
