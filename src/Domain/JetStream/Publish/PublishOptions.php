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
        private ScheduleOptions|null $schedule = null,
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
        ScheduleOptions|null $schedule = null,
    ): self {
        return new self(
            msgId: $msgId,
            expectedStream: $expectedStream,
            expectedLastSeq: $expectedLastSeq,
            expectedLastMsgId: $expectedLastMsgId,
            headers: $headers,
            schedule: $schedule,
        );
    }

    public function getSchedule(): ScheduleOptions|null
    {
        return $this->schedule;
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

        if ($this->schedule !== null) {
            if ($this->expectedStream !== null && $this->expectedStream !== $this->schedule->stream) {
                throw new InvalidArgumentException('Invalid expectedStream: conflicts with schedule stream');
            }
            $systemHeaders += $this->schedule->toHeaders();
            foreach ($systemHeaders as $name => $value) {
                if (preg_match('/[\r\n\x00]/', $value) === 1) {
                    throw new InvalidArgumentException('Invalid schedule header ' . $name);
                }
            }
            foreach ($this->headers as $name => $value) {
                $lower = strtolower($name);
                if (str_starts_with($lower, 'nats-schedule') || str_starts_with($lower, 'nats-batch-') || in_array($lower, ['nats-expected-stream', 'nats-required-api-level', 'nats-rollup', 'nats-ttl'], true)) {
                    throw new InvalidArgumentException('Custom headers conflict with schedule system header ' . $name);
                }
                if (preg_match('/[\r\n\x00]/', $name . $value) === 1) {
                    throw new InvalidArgumentException('Invalid schedule custom headers: control character');
                }
            }
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
