<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream;

use Dorpmaster\Nats\Domain\JetStream\Internal\AckSubjectMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AckSubjectMetadataTest extends TestCase
{
    public static function subjects(): iterable
    {
        $classic  = '$JS.ACK.ORDERS.C1.%s.10.20.1700000000000000000.0';
        $extended = '$JS.ACK.EU.hash-123.ORDERS.C1.%s.10.20.1700000000000000000.0';
        foreach ([$classic, $extended] as $format) {
            foreach (['1' => 1, '2' => 2, '5' => 5, '6' => 6, '0002' => 2, (string) PHP_INT_MAX => PHP_INT_MAX] as $value => $expected) {
                yield [sprintf($format, $value), $expected];
            }
            foreach (['0', '000', '-1', '+2', '1e2', ' 2', '2 ', "2\t", "2\r", "2\n", "2\r\n", "2\0", '٢', (string) PHP_INT_MAX . '0', '9223372036854775808', str_repeat('9', 100)] as $invalid) {
                yield [sprintf($format, $invalid), null];
            }
        }
        yield ['$JS.ACK._.hash.ORDERS.C1.6.1.2.3.0.token', 6];
        yield ['$JS.ACK._.hash.ORDERS.C1.6.1.2.3.0.' . str_repeat('1', 22), 6];
        yield ['$JS.ACK._.hash.ORDERS.C1.6.1.2.3.0.0', 6];
        // A dot separates fields; it does not encode a fractional counter.
        yield [sprintf($classic, '1.5'), null];
        yield [sprintf($extended, '1.5'), 1];
        foreach (['7', '00', '1234567890', str_repeat('9', 100), '7.0', '0.7.token.123', '1e2'] as $tail) {
            yield ['$JS.ACK.EU.hash.ORDERS.C1.6.10.25.1700000000000000000.0.' . $tail, 6];
        }
        yield ['$JS.ACK.EU.hash.ORDERS.C1.2.1.2.3.0.token.extension', 2];
        yield ['$JS.ACK.ORDERS@EU.WORKER+EU.2.1.2.3.0', 2];
        foreach ([null, '', 'ACK.ORDERS.C1.1', '$JS.ACK.ORDERS.C1.2', '$js.ACK.ORDERS.C1.2.1.2.3.0', '$JS.OTHER.ORDERS.C1.2.1.2.3.0', '$JS.ACK.ORDERS.C1.2.1.2.3.0.extra', '$JS.ACK..C1.2.1.2.3.0', '$JS.ACK.EU..ORDERS.C1.2.1.2.3.0', '$JS.ACK.EU.hash..C1.2.1.2.3.0', '$JS.ACK.ORDERS.C1..1.2.3.0', '$JS.ACK.ORDERS.C1.2..2.3.0', '$JS.ACK.ORDERS.C1.2.x.2.3.0', '$JS.ACK.ORDERS.C1.2.1.2.3.-1', '$JS.ACK.ORDERS.C1.2.1.2.3.0.', '$JS.ACK.EU.hash.ORDERS.C1.2.1.2.3.0.', '$JS.ACK.ORDERS.*.2.1.2.3.0', '$JS.ACK.ORDERS.>.2.1.2.3.0', "\$JS.ACK.ORDERS.C1.2.1.2.3.0\n"] as $invalid) {
            yield [$invalid, null];
        }
    }

    #[DataProvider('subjects')]
    public function testDeliveryCount(string|null $subject, int|null $expected): void
    {
        self::assertSame($expected, AckSubjectMetadata::deliveryCount($subject));
    }
}
