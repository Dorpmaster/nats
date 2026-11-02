<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Backup;

use Amp\{Cancellation,CompositeCancellation,DeferredCancellation};
use Dorpmaster\Nats\Domain\Client\{ConnectionBoundProtocolClientInterface,ConnectionBoundRequestException,PublishChannelInterface,ServerInformation};
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ApiError;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Transport\{JetStreamControlPlaneHeadersRequestMessage,JetStreamControlPlaneRequestMessage};
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Throwable;

/** @internal Every write and private subscription belongs to one connection generation. */
final class ArchiveSession
{
    public Cancellation $scope;
    public bool $lastAttempted     = false;
    public bool $confirmedFailure  = false;
    public Throwable|null $failure = null;
    private DeferredCancellation $stop;
    /** @var list<PublishChannelInterface> */
    private array $channels = [];
    private bool $closed    = false;
    public function __construct(public readonly ConnectionBoundProtocolClientInterface $client, public readonly ServerInformation $connection, public readonly ArchiveDeadline $deadline)
    {
        $this->stop  = new DeferredCancellation();
        $this->scope = new CompositeCancellation($deadline, $this->stop->getCancellation());
    }
    public static function inbox(): string
    {
        return '_INBOX.ARCHIVE.' . bin2hex(random_bytes(16));
    }
    public function channel(string $subject, ArchiveInbox $inbox): PublishChannelInterface
    {
        $this->deadline->remaining();
        $channel          = $this->client->openProtocolChannelOnConnection($subject, static fn ($message) => $inbox->push($message), function (Throwable $error) use ($inbox): void {
            if (!$this->closed) {
                $this->failure ??= $error;
                $inbox->fail($error);
                $this->stop->cancel($error);
            }
        }, $this->connection->connectionId, $this->scope);
        $this->channels[] = $channel;
        return $channel;
    }
    public function request(string $subject, string $payload, bool $requiredLevel = false): object
    {
        $this->lastAttempted    = false;
        $this->confirmedFailure = false;
        $this->scope->throwIfRequested();
        $reply   = self::inbox();
        $message = $requiredLevel
            ? new JetStreamControlPlaneHeadersRequestMessage($subject, $payload, new HeaderBag(['Nats-Required-Api-Level' => '5']), $reply)
            : new JetStreamControlPlaneRequestMessage($subject, $payload, $reply);
        $bytes   = $requiredLevel ? $message->getTotalSize() : strlen($payload);
        if ($this->connection->maxPayload === null || $bytes > $this->connection->maxPayload) {
            throw new JetStreamApiException(0, 'Archive request exceeds or cannot establish server max_payload');
        }
        try {
            $response            = $this->client->requestOnceOnConnection($message, $this->connection->connectionId, $this->deadline->remaining(), $this->scope);
            $this->lastAttempted = true;
            return $response;
        } catch (ConnectionBoundRequestException $error) {
            $this->lastAttempted = $error->wasAttempted();
            throw $error;
        }
    }
    public function json(object $message, string $type): JsonObject
    {
        if ($message instanceof StatusMessageInterface && ($status = $message->getStatus()) !== null) {
            throw new JetStreamApiException($status->code, $status->description);
        }
        if (!method_exists($message, 'getPayload') || !is_string($message->getPayload())) {
            throw new JetStreamApiException(0, 'Invalid archive response payload');
        }
        try {
            $raw = new JsonObject($message->getPayload());
        } catch (\JsonException $error) {
            throw new JetStreamApiException(0, 'Invalid archive JSON response', $error);
        }
        $error    = ApiError::fromResponse(json_decode($raw->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'Archive server error');
        $expected = $raw->get('type') === 'io.nats.jetstream.api.v1.' . $type;
        if ($error !== null) {
            // ApiError retains available details, but fallback/coerced fields cannot confirm refusal.
            $details                = $raw->getObject('error');
            $code                   = $details?->getRawValue('code') ?? '';
            $description            = $details?->getRawValue('description') ?? '';
            $this->confirmedFailure = $expected && preg_match('/\A[45][0-9]{2}\z/', $code) === 1
                && str_starts_with($description, '"') && ($error->getErrorCode() ?? 0) > 0
                && !in_array($error->getApiCode(), [408,504], true);
            throw $error;
        }
        if (!$expected) {
            throw new JetStreamApiException(0, 'Invalid archive response type: expected ' . $type);
        }
        return $raw;
    }
    public function close(): void
    {
        $this->closed = true;
        $this->deadline->close();
        foreach ($this->channels as $channel) {
            try {
                $channel->close();
            } catch (Throwable) {
            }
        }
        $this->channels = [];
        $this->stop->cancel();
    }
}
