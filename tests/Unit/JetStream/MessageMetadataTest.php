<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream;

use Dorpmaster\Nats\Domain\JetStream\Internal\AckSubjectMetadata;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\MessageMetadata;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageMetadataInterface;
use Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageMetadataTest extends TestCase
{
    public static function formats(): iterable
    {
        yield 'classic' => ['$JS.ACK.ORDERS@EU.WORKER+EU.', null, null];
        foreach (['', '.7', '.token', '.0.7.token.' . str_repeat('9', 100)] as $tail) {
            yield 'extended ' . $tail => ['$JS.ACK.EU.hash.ORDERS@EU.WORKER+EU.', 'EU', $tail];
            yield 'empty domain ' . $tail => ['$JS.ACK._.hash.ORDERS@EU.WORKER+EU.', '', $tail];
        }
    }

    #[DataProvider('formats')]
    public function testExactMetadataAndOpaqueExtensions(string $prefix, string|null $domain, string|null $tail): void
    {
        $reply    = $prefix . '0006.18446744073709551615.9223372036854775808.1700000000123456789.0000' . $tail;
        $metadata = AckSubjectMetadata::parse($reply);
        self::assertNotNull($metadata);
        self::assertSame('ORDERS@EU', $metadata->stream);
        self::assertSame('WORKER+EU', $metadata->consumer);
        self::assertSame('18446744073709551615', $metadata->streamSequence);
        self::assertSame('9223372036854775808', $metadata->consumerSequence);
        self::assertSame('6', $metadata->deliveryCount);
        self::assertSame('0', $metadata->pending);
        self::assertSame($domain, $metadata->domain);
        self::assertSame($domain === null ? null : 'hash', $metadata->accountHash);
        self::assertSame('1700000000123456789', $metadata->timestamp->getUnixNanoseconds());
        self::assertSame('2023-11-14T22:13:20.123456789Z', $metadata->timestamp->getRfc3339());
        self::assertSame('123456', $metadata->timestamp->toDateTimeImmutable()->format('u'));
        self::assertSame(6, AckSubjectMetadata::deliveryCount($reply));
    }

    public static function invalidNumbers(): iterable
    {
        foreach (['0', '-1', '+1', '1e2', '18446744073709551616', ' 1', '١', '1\n'] as $bad) {
            foreach ([0, 1, 2] as $position) {
                yield [$position, $bad];
            }
        }
        foreach (['-1', '+1', '1e2', '9223372036854775808'] as $bad) {
            yield [3, $bad];
        }
        foreach (['-1', '1e2', '18446744073709551616'] as $bad) {
            yield [4, $bad];
        }
    }

    #[DataProvider('invalidNumbers')]
    public function testInvalidFullMetadataDoesNotThrow(int $position, string $bad): void
    {
        $values            = ['6', '10', '25', '1700000000000000000', '0'];
        $values[$position] = $bad;
        foreach (['$JS.ACK.S.C.', '$JS.ACK.EU.hash.S.C.'] as $prefix) {
            self::assertNull(AckSubjectMetadata::parse($prefix . implode('.', $values)));
        }
    }

    public function testLegacyCountSurvivesOtherFieldRangeFailures(): void
    {
        foreach (['6.0.2.3.0', '6.18446744073709551616.2.3.0', '6.1.2.9223372036854775808.0'] as $fields) {
            self::assertSame(6, AckSubjectMetadata::deliveryCount('$JS.ACK.S.C.' . $fields));
            self::assertNull(AckSubjectMetadata::parse('$JS.ACK.S.C.' . $fields));
        }
        foreach ([(string) PHP_INT_MAX, '9223372036854775808', '18446744073709551615'] as $count) {
            $reply = '$JS.ACK.S.C.' . $count . '.1.1.0.18446744073709551615';
            self::assertSame($count, AckSubjectMetadata::parse($reply)?->deliveryCount);
            self::assertSame($count === (string) PHP_INT_MAX ? PHP_INT_MAX : null, AckSubjectMetadata::deliveryCount($reply));
        }
        foreach ([null, '', '$JS.FC.S.C.1.2.3.4.5', '$JS.ACK.S.C.6.1.2.3.0.tail'] as $reply) {
            self::assertNull(AckSubjectMetadata::parse($reply));
        }
    }

    public function testOldConstructorAndIndependentOptionalCapability(): void
    {
        $message = new JetStreamMessage(subject: 'orders', payload: "\0\r\n", headers: ['X' => 'a'], replyTo: '$JS.ACK.S.C.2.1.1.0.0', sizeBytes: 3, deliveryCount: 99);
        self::assertInstanceOf(JetStreamMessageMetadataInterface::class, $message);
        self::assertSame(99, $message->getDeliveryCount());
        self::assertSame('2', $message->getMetadata()?->deliveryCount);
        self::assertSame(['X' => ['a']], $message->getHeaderValues());
        $headers        = $message->getHeaderValues();
        $headers['X'][] = 'changed';
        self::assertSame(['X' => ['a']], $message->getHeaderValues());
        try {
            $message->getMetadata()->deliveryCount = '999';
            self::fail('Metadata must be immutable');
        } catch (\Error) {
            self::assertSame('2', $message->getMetadata()->deliveryCount);
        }
    }

    public function testNewModelsNormalizeCountersAndBreakHeaderReferences(): void
    {
        $metadata = new MessageMetadata('S', 'C', '0001', '0002', '0006', '0000', NanoTimestamp::fromUnixNanoseconds('0'));
        self::assertSame('1', $metadata->streamSequence);
        self::assertSame('2', $metadata->consumerSequence);
        self::assertSame('6', $metadata->deliveryCount);
        self::assertSame('0', $metadata->pending);
        $value   = 'original';
        $source  = ['X' => [&$value]];
        $message = new JetStreamMessage('s', '', [], null, 0, headerValues: $source);
        $value   = 'changed';
        self::assertSame(['X' => ['original']], $message->getHeaderValues());
    }

    public static function timestamps(): iterable
    {
        yield ['1970-01-01T00:00:00Z', '0'];
        yield ['1970-01-01T00:00:00.000000001Z', '1'];
        yield ['1969-12-31T23:59:59.999999999Z', '-1'];
        yield ['1969-12-31T23:59:59.000000001Z', '-999999999'];
        yield ['0001-01-01T00:00:00Z', '-62135596800000000000'];
        yield ['2023-11-15T00:13:20.123456789+02:00', '1700000000123456789'];
        yield ['2262-04-11T23:47:16.854775807Z', '9223372036854775807'];
        yield ['9999-12-31T23:59:59.999999999Z', '253402300799999999999'];
    }

    #[DataProvider('timestamps')]
    public function testTimestampPreservesNanosecondsAndOffset(string $source, string $expected): void
    {
        $timestamp = NanoTimestamp::fromRfc3339($source);
        self::assertSame($source, $timestamp->getRfc3339());
        self::assertSame($expected, $timestamp->getUnixNanoseconds());
        if ($expected[0] !== '-' && (strlen($expected) < 19 || $expected === '9223372036854775807')) {
            self::assertSame($expected, NanoTimestamp::fromUnixNanoseconds($expected)->getUnixNanoseconds());
        }
    }

    public static function badTimestamps(): iterable
    {
        foreach (['', '2023-02-29T00:00:00Z', '2024-01-01T24:00:00Z', '2024-01-01T00:00:00.1234567890Z', '2024-01-01T00:00:00+24:00', 'today', "1970-01-01T00:00:00Z\n"] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('badTimestamps')]
    public function testInvalidTimestampIsControlled(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        NanoTimestamp::fromRfc3339($value);
    }
}
