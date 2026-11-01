<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;

/** @internal Typed control messages preserve original JSON number token types. */
final readonly class FastBatchResponse
{
    private function __construct(public string $kind, public string|null $sequence = null, public int|null $window = null, public string|null $gapStart = null, public JetStreamApiException|null $error = null, public JsonObject|null $raw = null)
    {
    }
    public static function parse(MsgMessageInterface|HMsgMessageInterface $message): self
    {
        if ($message instanceof StatusMessageInterface && ($status = $message->getStatus()) !== null) {
            return new self('error', error: new JetStreamApiException($status->code, $status->description));
        }
        $raw    = new JsonObject($message->getPayload());
        $fields = new MetadataFields($raw, 'fast_batch');
        $kind   = $fields->text('type');
        if ($kind === 'ack') {
            $seq    = $fields->decimal('seq');
            $window = $fields->decimal('msgs', '65535');
            if ($seq === null || $window === null || $window === '0') {
                throw new JetStreamApiException(0, 'Invalid fast batch ACK');
            }
            return new self('ack', $seq, (int) $window, raw: $raw);
        }
        if ($kind === 'gap') {
            $seq  = $fields->decimal('seq');
            $last = $fields->decimal('last_seq');
            if ($seq === null || $last === null || $last === '0' || Decimal::compare($seq, $last) <= 0) {
                throw new JetStreamApiException(0, 'Invalid fast batch gap');
            }
            return new self('gap', $seq, gapStart: $last, raw: $raw);
        }
        if ($raw->has('error')) {
            $error = $fields->object('error');
            if ($error === null) {
                throw new JetStreamApiException(0, 'Invalid fast batch error');
            }
            $ef          = new MetadataFields($error, 'fast_batch.error');
            $code        = $ef->decimal('code', '599');
            $description = $ef->text('description');
            $errCode     = $ef->decimal('err_code', (string) PHP_INT_MAX);
            if ($code === null || (int) $code < 300 || $description === null || $description === '' || ($error->has('err_code') && $errCode === null)) {
                throw new JetStreamApiException(0, 'Invalid fast batch error fields');
            }
            $seq = $kind === 'err' ? $fields->decimal('seq') : null;
            if ($kind !== null && ($kind !== 'err' || $seq === null || $seq === '0')) {
                throw new JetStreamApiException(0, 'Invalid fast batch error sequence/type');
            }
            return new self($kind === 'err' ? 'err' : 'error', $seq, error: new JetStreamApiException((int) $code, $description, errorCode: $errCode === null ? null : (int) $errCode), raw: $raw);
        }
        if ($kind !== null) {
            throw new JetStreamApiException(0, 'Unknown fast batch response type');
        }
        return new self('final', raw: $raw);
    }
}
