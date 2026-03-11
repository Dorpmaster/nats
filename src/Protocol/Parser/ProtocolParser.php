<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Parser;

use Amp\Parser\Parser;
use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\ProtocolParserInterface;
use Dorpmaster\Nats\Protocol\ErrMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\OkMessage;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PongMessage;

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
            $chunk = yield NatsProtocolMessageInterface::DELIMITER;

            $metadata = self::extractMetadata($chunk);
            if (count($metadata) === 0) {
                throw new \RuntimeException('Received a malformed message');
            }

            $type        = array_shift($metadata);
            $messageType = NatsMessageType::tryFrom($type);

            $message = match ($messageType) {
                NatsMessageType::INFO => self::parseToInfoMessage(implode(' ', $metadata)),
                NatsMessageType::MSG => yield from self::parseToMsg($metadata),
                NatsMessageType::HMSG => yield from self::parseToHMsg($metadata),
                NatsMessageType::PING => new PingMessage(),
                NatsMessageType::PONG => new PongMessage(),
                NatsMessageType::OK => new OkMessage(),
                NatsMessageType::ERR => new ErrMessage(implode(' ', $metadata)),
                default => throw new \RuntimeException(sprintf('Unknown message type "%s"', $type)),
            };

            $callback($message);
        }
    }

    private static function parseToInfoMessage(string $payload): InfoMessageInterface
    {
        return new InfoMessage($payload);
    }

    /**
     * @param array<string> $metadata
     * @return \Generator
     */
    private static function parseToMsg(array $metadata): \Generator
    {
        match (count($metadata)) {
            3 => [$subject, $sid, $size]           = $metadata,
            4 => [$subject, $sid, $replyTo, $size] = $metadata,
            default => throw new \RuntimeException('Malformed MSG message'),
        };

        if (!self::isUnsignedInteger($size)) {
            throw new \RuntimeException('MSG payload size must be numeric');
        }

        $payloadSize = (int) $size;
        $payload     = yield $payloadSize;

        $delimiter = yield 2;
        if ($delimiter !== NatsProtocolMessageInterface::DELIMITER) {
            throw new \RuntimeException('Malformed MSG message payload delimiter');
        }

        if (strlen($payload) !== $payloadSize) {
            throw new \RuntimeException('Malformed MSG message payload length');
        }

        return new MsgMessage($subject, $sid, $payload, $replyTo ?? null);
    }

    /**
     * @param array<string> $metadata
     * @return \Generator
     */
    private static function parseToHMsg(array $metadata): \Generator
    {
        match (count($metadata)) {
            4 => [$subject, $sid, $headersSize, $totalSize]           = $metadata,
            5 => [$subject, $sid, $replyTo, $headersSize, $totalSize] = $metadata,
            default => throw new \RuntimeException('Malformed HMSG message'),
        };

        if (!self::isUnsignedInteger($headersSize) || !self::isUnsignedInteger($totalSize)) {
            throw new \RuntimeException('HMSG sizes must be numeric');
        }

        $headersLength = (int) $headersSize;
        $totalLength   = (int) $totalSize;
        if ($headersLength > $totalLength) {
            throw new \RuntimeException('Malformed HMSG message sizes');
        }

        $payloadWithHeaders = yield $totalLength;
        if (strlen($payloadWithHeaders) !== $totalLength) {
            throw new \RuntimeException('Malformed HMSG message payload length');
        }

        $headersPayload = substr($payloadWithHeaders, 0, $headersLength);
        $payload        = substr($payloadWithHeaders, $headersLength);
        $headers        = self::parseHeaders($headersPayload);

        $delimiter = yield 2;
        if ($delimiter !== NatsProtocolMessageInterface::DELIMITER) {
            throw new \RuntimeException('Malformed HMSG message payload delimiter');
        }

        return new HMsgMessage($subject, $sid, $payload, $headers, $replyTo ?? null);
    }

    /**
     * Removes all possible whitespaces around message metadata.
     *
     * @return list<string>
     */
    private static function extractMetadata(string $metadata): array
    {
        return array_values(
            array_filter(
                array_map('trim', explode(' ', $metadata)),
                static fn(string $value): bool => trim($value) !== '',
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

    private static function isUnsignedInteger(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }
}
