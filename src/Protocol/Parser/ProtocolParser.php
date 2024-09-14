<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Parser;

use Amp\Parser\Parser;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\ProtocolParserInterface;
use Dorpmaster\Nats\Protocol\InfoMessage;
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
                default => throw new \RuntimeException(sprintf('Unknown message type "%s"', $type)),
            };

            $callback($message);
        }
    }

    private static function parseToInfoMessage(string $payload): InfoMessageInterface
    {
        return new InfoMessage($payload);
    }

}
