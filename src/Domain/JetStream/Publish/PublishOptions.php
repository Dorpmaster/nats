<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use InvalidArgumentException;

final readonly class PublishOptions
{
    private const string HEADER_MSG_ID                 = 'Nats-Msg-Id';
    private const string HEADER_EXPECTED_STREAM        = 'Nats-Expected-Stream';
    private const string HEADER_EXPECTED_LAST_SEQUENCE = 'Nats-Expected-Last-Sequence';
    private const string HEADER_EXPECTED_LAST_MSG_ID   = 'Nats-Expected-Last-Msg-Id';

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string|null $msgId = null,
        private string|null $expectedStream = null,
        private int|null $expectedLastSeq = null,
        private string|null $expectedLastMsgId = null,
        array $headers = [],
    ) {
        if ($this->expectedLastSeq !== null && $this->expectedLastSeq <= 0) {
            throw new InvalidArgumentException('expectedLastSeq must be greater than zero');
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Custom header name must be a non-empty string');
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('Custom header "%s" value must be a string', $name));
            }

            $normalized[$name] = $value;
        }

        $this->headers = $normalized;
    }

    public static function create(
        string|null $msgId = null,
        string|null $expectedStream = null,
        int|null $expectedLastSeq = null,
        string|null $expectedLastMsgId = null,
        array $headers = [],
    ): self {
        return new self(
            msgId: $msgId,
            expectedStream: $expectedStream,
            expectedLastSeq: $expectedLastSeq,
            expectedLastMsgId: $expectedLastMsgId,
            headers: $headers,
        );
    }

    /** @return array<string, string> */
    public function toHeaders(): array
    {
        $systemHeaders = [];

        if ($this->msgId !== null) {
            $systemHeaders[self::HEADER_MSG_ID] = $this->msgId;
        }

        if ($this->expectedStream !== null) {
            $systemHeaders[self::HEADER_EXPECTED_STREAM] = $this->expectedStream;
        }

        if ($this->expectedLastSeq !== null) {
            $systemHeaders[self::HEADER_EXPECTED_LAST_SEQUENCE] = (string) $this->expectedLastSeq;
        }

        if ($this->expectedLastMsgId !== null) {
            $systemHeaders[self::HEADER_EXPECTED_LAST_MSG_ID] = $this->expectedLastMsgId;
        }

        $systemHeaderNames = array_map('strtolower', array_keys($systemHeaders));

        foreach (array_keys($this->headers) as $headerName) {
            if (in_array(strtolower($headerName), $systemHeaderNames, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Custom headers must not override JetStream system header "%s"',
                    $headerName,
                ));
            }
        }

        return array_merge($systemHeaders, $this->headers);
    }
}
