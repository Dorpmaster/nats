<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Parser;

use Amp\Parser\Parser;
use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\ProtocolParserInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;

final readonly class ProtocolParser implements ProtocolParserInterface
{
    private Parser $parser;

    public function __construct(\Closure $callback)
    {
        $this->parser = new Parser(self::parser($callback));
    }

    public function push(string $chunk): void
    {
        $this->parser->push($chunk);
    }

    public function cancel(): void
    {
        $this->parser->cancel();
    }


    private static function parser(\Closure $callback): \Generator
    {
        while (true) {
            $type = yield ' ';
            $messageType = NatsMessageType::tryFrom($type);

            $message = match ($messageType) {
                NatsMessageType::INFO => self::parseToInfoMessage(yield NatsProtocolMessageInterface::DELIMITER),
                NatsMessageType::MSG => yield from self::parseToMsg(),
                NatsMessageType::HMSG => yield from self::parseToHMsg(),
                default => throw new \RuntimeException(sprintf('Unknown message type "%s"', $type)),
            };

            $callback($message);
        }
    }

    private static function parseToInfoMessage(string $payload): InfoMessageInterface
    {
        return new InfoMessage(trim($payload));
    }

    private static function parseToMsg():\Generator
    {
        $metadata = trim(yield NatsProtocolMessageInterface::DELIMITER);
        $parts = self::extractMetadata($metadata);

        match (count($parts)) {
            3 => [$subject, $sid, $size] = $parts,
            4 => [$subject, $sid, $replyTo, $size] = $parts,
            default => throw new \RuntimeException('Malformed MSG message'),
        };

        $payload = trim(yield (int) $size);

        if (isset($replyTo)) {
            $replyTo = trim($replyTo);
        }

        yield 2; // Remove trailing CRLF

        return new MsgMessage(trim($subject), trim($sid), trim($payload), $replyTo ?? null);
    }

    private static function parseToHMsg():\Generator
    {
        $metadata = trim(yield NatsProtocolMessageInterface::DELIMITER);
        $parts = self::extractMetadata($metadata);

        match (count($parts)) {
            4 => [$subject, $sid, $headersSize, $totalSize] = $parts,
            5 => [$subject, $sid, $replyTo, $headersSize, $totalSize] = $parts,
            default => throw new \RuntimeException('Malformed MSG message'),
        };

        $payloadWithHeaders = trim(yield (int) $totalSize);
        $headersPayload = substr($payloadWithHeaders, 0, (int) $headersSize);
        $payload = substr($payloadWithHeaders, (int) $headersSize);
        $headers = self::parseHeaders($headersPayload);

        if (isset($replyTo)) {
            $replyTo = trim($replyTo);
        }

        yield 2; // Remove trailing CRLF

        return new HMsgMessage(trim($subject), trim($sid), trim($payload), $headers, $replyTo ?? null);
    }

    /**
     * Removes all possible whitespaces around message metadata.
     */
    private static function extractMetadata(string $metadata): array
    {
        return array_values(
            array_filter(
                explode(' ', $metadata),
                static fn(mixed $value): bool => is_string($value) && trim($value) !== '',
            )
        );
    }

    private static function parseHeaders(string $payload): HeaderBugInterface
    {
        $headers = new HeaderBag();

        foreach (explode(NatsProtocolMessageInterface::DELIMITER, $payload) as $item) {
            if (str_contains($item, 'NATS/') === true) {
                continue;
            }

            $parts = explode(':', $item);
            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;

            $headers->set($key, trim($value), false);
        }

        return $headers;
    }
}
