<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Dorpmaster\Nats\Domain\JetStream\Internal\DeadlineCancellation;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\Support;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Exception\ScheduledPublishException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ApiError;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\PubAck;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneHeadersRequestMessage;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/** @internal The typed path owns one deadline and one removable definition write. */
final readonly class ScheduledPublisher
{
    public function __construct(private ClientInterface $client, private LoggerInterface $logger)
    {
    }
    public function publish(string $subject, string $payload, PublishOptions $options, int|null $timeoutMs): PubAck
    {
        $started  = hrtime(true);
        $schedule = $options->getSchedule();
        assert($schedule !== null);
        $schedule->validateDefinition($subject);
        $timeout = $timeoutMs ?? 2000;
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Invalid timeoutMs: expected positive milliseconds');
        }
        $schedule->validateTiming((int) ceil($timeout / 1000));
        $headers         = $options->toHeaders();
        $message         = new JetStreamControlPlaneHeadersRequestMessage($subject, $payload, new HeaderBag($headers));
        $deadline        = $started + $timeout * 1000000;
        $mayHaveBeenSent = false;
        try {
            $capabilities = JetStreamCapabilities::forClient($this->client);
            $server       = $capabilities->server;
            if (!$this->client instanceof ConnectionBoundRequestClientInterface || $capabilities->check($schedule->feature())->support !== Support::SUPPORTED || $server->connectionId === null) {
                throw new JetStreamApiException(0, 'Scheduled publication support is not established on the current connection');
            }
            $scope = new DeadlineCancellation($deadline);
            (new ScheduledPublishTarget($this->client, $server->connectionId, $deadline, $scope))->verify($schedule, $subject, $server->serverId);
            $remaining = ($deadline - hrtime(true)) / 1000000000;
            if ($remaining <= 0) {
                throw new JetStreamTimeoutException('Scheduled publication timed out before definition write');
            }
            $this->logger->debug('js.schedule.publish.start', ['stream' => $schedule->stream, 'subject' => $subject, 'api_level' => $schedule->feature()->minimumApiLevel()]);
            $mayHaveBeenSent = true;
            try {
                $reply = $this->client->requestOnceOnConnection($message, $server->connectionId, $remaining, $scope);
            } catch (ConnectionBoundRequestException $error) {
                $mayHaveBeenSent = $error->wasAttempted();
                throw $error;
            }
            if ($reply instanceof StatusMessageInterface && ($status = $reply->getStatus()) !== null) {
                throw new JetStreamApiException($status->code, $status->description);
            }
            $raw   = new JsonObject($reply->getPayload());
            $error = ApiError::fromResponse(json_decode($raw->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'Scheduled publication rejected');
            if ($error !== null) {
                if ($error->getApiCode() >= 400 && $error->getApiCode() < 500 && $error->getApiCode() !== 408 && ($error->getErrorCode() ?? 0) >= 10000) {
                    $mayHaveBeenSent = false;
                }
                throw $error;
            }
            $fields    = new MetadataFields($raw, 'schedule.puback');
            $seq       = $fields->decimal('seq', (string) PHP_INT_MAX);
            $duplicate = $fields->boolean('duplicate') ?? false;
            if ($fields->text('stream') !== $schedule->stream || $seq === null || $seq === '0') {
                throw new JetStreamApiException(0, 'Invalid scheduled definition PubAck stream or seq');
            }
            $ack = new PubAck($schedule->stream, (int) $seq, $duplicate);
            $this->logger->debug('js.schedule.publish.ack', ['stream' => $ack->getStream(), 'seq' => $ack->getSeq()]);
            return $ack;
        } catch (Throwable $error) {
            $this->logger->warning('js.schedule.publish.failed', ['stream' => $schedule->stream, 'subject' => $subject, 'outcome_unknown' => $mayHaveBeenSent, 'error_type' => $error::class]);
            throw new ScheduledPublishException($schedule->stream, $subject, $mayHaveBeenSent, $error);
        }
    }
}
