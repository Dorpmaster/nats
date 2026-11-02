<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneHeadersRequestMessage;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneRequestMessage;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamRawPubMessage;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Protocol\SubMessage;
use Dorpmaster\Nats\Protocol\UnSubMessage;
use Dorpmaster\Nats\Tests\Support\InvalidNatsArguments;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundCommandValidationTest extends TestCase
{
    #[DataProvider('invalidPublishFields')]
    public function testRejectsInvalidPublishFields(string $frame, string $field, string $value): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage($field === 'subject' ? 'Subject' : 'Reply-To');

        self::publishFrame($frame, $field === 'subject' ? $value : '$JS.API.STREAM.INFO.ORDERS', $field === 'replyTo' ? $value : '_INBOX.reply');
    }

    public static function invalidPublishFields(): iterable
    {
        foreach (self::publishFrames() as $frame) {
            foreach (['subject', 'replyTo'] as $field) {
                foreach (InvalidNatsArguments::commandTokens() as $label => $value) {
                    yield "$frame-$field-$label" => [$frame, $field, $value];
                }
            }
        }
    }

    #[DataProvider('invalidSubscriptionFields')]
    public function testRejectsInvalidSubscriptionFields(string $field, string $value): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage(match ($field) {
            'subject' => 'Subject',
            'queueGroup' => 'Queue Group',
            default => 'SID',
        });

        if ($field === 'unsubSid') {
            new UnSubMessage($value);
            return;
        }

        new SubMessage($field === 'subject' ? $value : 'orders.*', $field === 'sid' ? $value : 'sid1', $field === 'queueGroup' ? $value : 'workers');
    }

    public static function invalidSubscriptionFields(): iterable
    {
        foreach (['subject', 'sid', 'queueGroup', 'unsubSid'] as $field) {
            foreach (InvalidNatsArguments::commandTokens() as $label => $value) {
                yield "$field-$label" => [$field, $value];
            }
        }
    }

    #[DataProvider('validPublishFrames')]
    public function testPreservesBinaryPayloadAndCompositeAddresses(string $frame, string $subject, string $replyTo): void
    {
        $payload = "before\r\nPUB untouched 1\r\n\0after";
        $message = self::publishFrame($frame, $subject, $replyTo, $payload);

        self::assertSame($subject, $message->getSubject());
        self::assertSame($replyTo, $message->getReplyTo());
        self::assertSame($payload, $message->getPayload());
        self::assertSame(strlen($payload), $message->getPayloadSize());
        self::assertStringEndsWith("\r\n" . $payload . "\r\n", (string) $message);
    }

    public static function validPublishFrames(): iterable
    {
        foreach (self::publishFrames() as $frame) {
            yield "$frame-api" => [$frame, '$JS.API.STREAM.INFO.ORDERS', '_INBOX.reply'];
            yield "$frame-ack" => [$frame, '$JS.ACK.ORDERS.C1.1.2.3.4.5', 'INBOX.reply'];
        }
    }

    #[DataProvider('extendedJetStreamAddresses')]
    public function testJetStreamFramesPreserveExtendedAddressCharacters(string $frame, string $subject, string|null $replyTo): void
    {
        $payload  = "data\r\n\0";
        $message  = self::publishFrame($frame, $subject, $replyTo, $payload);
        $reply    = $replyTo === null ? '' : ' ' . $replyTo;
        $headers  = "NATS/1.0\r\nTest: value\r\n\r\n";
        $expected = $frame === 'headers-request'
            ? 'HPUB ' . $subject . $reply . ' ' . strlen($headers) . ' ' . (strlen($headers) + strlen($payload)) . "\r\n" . $headers . $payload . "\r\n"
            : 'PUB ' . $subject . $reply . ' ' . strlen($payload) . "\r\n" . $payload . "\r\n";

        self::assertSame($subject, $message->getSubject());
        self::assertSame($replyTo, $message->getReplyTo());
        self::assertSame($expected, (string) $message);
    }

    public static function extendedJetStreamAddresses(): iterable
    {
        foreach (['raw', 'request', 'headers-request'] as $frame) {
            foreach (['ORDERS@EU', 'ORDERS+EU'] as $stream) {
                foreach (['STREAM.INFO.' . $stream, 'CONSUMER.INFO.' . $stream . '.WORKER@EU'] as $suffix) {
                    foreach ($frame === 'request' ? ['_INBOX.reply@EU+1'] : [null, '_INBOX.reply@EU+1'] as $replyTo) {
                        yield "$frame-$suffix-" . ($replyTo ?? 'no-reply') => [$frame, '$JS.API.' . $suffix, $replyTo];
                    }
                }
            }
        }
    }

    #[DataProvider('extendedCoreAddresses')]
    public function testCorePublishContractStillRejectsExtendedCharacters(string $frame, string $field, string $value): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage($field === 'subject' ? 'Subject' : 'Reply-To');

        self::publishFrame($frame, $field === 'subject' ? $value : 'orders.eu', $field === 'replyTo' ? $value : 'reply.eu');
    }

    public static function extendedCoreAddresses(): iterable
    {
        foreach (['pub', 'hpub'] as $frame) {
            foreach (['subject', 'replyTo'] as $field) {
                foreach (['ORDERS@EU', 'ORDERS+EU', 'WORKER@EU'] as $value) {
                    yield "$frame-$field-$value" => [$frame, $field, $value];
                }
            }
        }
    }

    public function testSubscriptionPatternsRemainSupported(): void
    {
        self::assertSame("SUB orders.* workers sid1\r\n", (string) new SubMessage('orders.*', 'sid1', 'workers'));
        self::assertSame("SUB orders.> sid2\r\n", (string) new SubMessage('orders.>', 'sid2'));
    }

    private static function publishFrames(): array
    {
        return ['pub', 'hpub', 'raw', 'request', 'headers-request'];
    }

    private static function publishFrame(string $frame, string $subject, string|null $replyTo, string $payload = 'data'): NatsProtocolMessageInterface
    {
        return match ($frame) {
            'pub' => new PubMessage($subject, $payload, $replyTo),
            'hpub' => new HPubMessage($subject, $payload, new HeaderBag(['Test' => 'value']), $replyTo),
            'raw' => new JetStreamRawPubMessage($subject, $payload, $replyTo),
            'request' => new JetStreamControlPlaneRequestMessage($subject, $payload, $replyTo),
            'headers-request' => new JetStreamControlPlaneHeadersRequestMessage($subject, $payload, new HeaderBag(['Test' => 'value']), $replyTo),
        };
    }
}
