<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;

final class ProtocolChunkFeeder
{
    /** @var list<NatsProtocolMessageInterface> */
    private array $messages = [];

    private ProtocolParser $parser;

    public function __construct()
    {
        $this->parser = new ProtocolParser(function (NatsProtocolMessageInterface $message): void {
            $this->messages[] = $message;
        });
    }

    /** @param list<string> $chunks */
    public function feed(array $chunks): void
    {
        foreach ($chunks as $chunk) {
            $this->push($chunk);
        }
    }

    public function push(string $chunk): void
    {
        $this->parser->push($chunk);
    }

    public function cancel(): void
    {
        $this->parser->cancel();
    }

    /** @return list<NatsProtocolMessageInterface> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function remainder(): null
    {
        // ProtocolParser does not expose internal buffer remainder.
        return null;
    }
}
