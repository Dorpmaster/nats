<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\Support;
use Dorpmaster\Nats\Domain\JetStream\Exception\AtomicBatchException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ApiError;
use Dorpmaster\Nats\Domain\JetStream\Internal\AtomicBatchFrame;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Internal\ResourceName;
use Dorpmaster\Nats\Domain\JetStream\Model\AtomicBatchAck;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/** @internal Serial atomic protocol, separate from the existing retryable single publish operation. */
final readonly class AtomicBatchPublisher
{
    public function __construct(private ClientInterface $client, private LoggerInterface $logger)
    {
    }

    public function publish(string $stream, array $messages, AtomicBatchOptions|null $options, mixed $timeoutMs): AtomicBatchAck
    {
        $started = hrtime(true);
        ResourceName::validate($stream, 'stream');
        $timeout = $timeoutMs ?? 2000;
        if (!is_int($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('Invalid timeoutMs: expected a positive integer');
        }
        $deadline  = $started + $timeout * 1_000_000;
        $options ??= new AtomicBatchOptions();
        $snapshots = $this->snapshot($messages);
        if (!$this->client instanceof ConnectionBoundRequestClientInterface) {
            throw new JetStreamApiException(0, 'Atomic publish requires ConnectionBoundRequestClientInterface');
        }
        $capabilities = JetStreamCapabilities::forClient($this->client);
        $feature      = $options->commitMode === AtomicBatchCommitMode::EOB ? Feature::ATOMIC_EOB : Feature::ATOMIC_PUBLISH;
        foreach (array_unique([Feature::ATOMIC_PUBLISH->value, $feature->value]) as $value) {
            if ($capabilities->check(Feature::from($value))->support !== Support::SUPPORTED) {
                throw new JetStreamApiException(0, 'Atomic publish mode support is not established: ' . $value);
            }
        }
        $connectionId = $capabilities->server->connectionId;
        if ($connectionId === null) {
            throw new JetStreamApiException(0, 'Atomic publish requires current connection identity');
        }
        $id = bin2hex(random_bytes(16));
        try {
            (new AtomicBatchTarget($this->client))->verify($stream, array_column($snapshots, 'subject'), $feature, $connectionId, $deadline, $options, $capabilities->server->serverId);
        } catch (Throwable $error) {
            throw new AtomicBatchException($id, false, $error);
        }
        $count  = count($snapshots);
        $frames = [];
        if ($options->commitMode === AtomicBatchCommitMode::EOB) {
            $snapshots[] = ['subject' => $snapshots[$count - 1]['subject'], 'payload' => '', 'headers' => []];
        }
        foreach ($snapshots as $index => $message) {
            $headers = $message['headers'] + ['Nats-Batch-Id' => $id, 'Nats-Batch-Sequence' => (string) ($index + 1), 'Nats-Expected-Stream' => $stream, 'Nats-Required-Api-Level' => (string) $feature->minimumApiLevel()];
            if ($index === count($snapshots) - 1) {
                $headers['Nats-Batch-Commit'] = $options->commitMode === AtomicBatchCommitMode::EOB ? 'eob' : '1';
            }
            $frames[] = new AtomicBatchFrame($message['subject'], $message['payload'], $headers, '_INBOX.BATCH.' . bin2hex(random_bytes(16)));
        }
        $this->logger->debug('js.atomic.start', ['operation' => 'atomic_publish', 'batch' => $id, 'count' => $count, 'mode' => $options->commitMode->value]);
        foreach ($frames as $index => $frame) {
            $commit      = $index === count($frames) - 1;
            $entered     = false;
            $serverError = null;
            try {
                $options->cancellation?->throwIfRequested();
                $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
                if ($remaining <= 0) {
                    throw new JetStreamTimeoutException('Atomic batch deadline expired');
                }
                $current = JetStreamCapabilities::forClient($this->client);
                if ($current->server->connectionId !== $connectionId || $current->check($feature)->support !== Support::SUPPORTED) {
                    throw new JetStreamApiException(0, 'The original atomic publish connection is unavailable');
                }
                $entered  = true;
                $response = $this->client->requestOnceOnConnection($frame, $connectionId, $remaining, $options->cancellation);
                if ($response instanceof StatusMessageInterface && ($status = $response->getStatus()) !== null) {
                    if ($status->code >= 300 && $status->code <= 599) {
                        // A status-only response may come from a gateway, not the stream's commit decision.
                        throw new JetStreamApiException($status->code, $status->description);
                    }
                    throw new JetStreamApiException(0, 'Unexpected atomic response status');
                }
                $payload = $response->getPayload();
                if ($payload === '') {
                    if ($commit) {
                        throw new JetStreamApiException(0, 'Empty response cannot confirm atomic commit');
                    }
                    $this->logger->debug('js.atomic.staged', ['batch' => $id, 'sequence' => $index + 1]);
                    continue;
                }
                $raw = new JsonObject($payload);
                if ($raw->has('error')) {
                    $errorObject = $raw->getObject('error');
                    if ($errorObject === null) {
                        throw new JetStreamApiException(0, 'Invalid atomic error object');
                    }
                    $errorFields = new MetadataFields($errorObject, 'atomic_ack.error');
                    $code        = $errorFields->decimal('code', '599');
                    $description = $errorFields->text('description');
                    if (
                        $code === null || (int) $code < 300 || $description === null || $description === ''
                        || ($errorObject->has('err_code') && $errorFields->decimal('err_code', (string) PHP_INT_MAX) === null)
                    ) {
                        throw new JetStreamApiException(0, 'Invalid atomic error code or description');
                    }
                }
                $apiError = ApiError::fromResponse(json_decode($raw->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'Atomic publish error');
                if ($apiError !== null) {
                    // Only a JetStream-specific client rejection confirms failure; generic timeouts do not.
                    $serverError = $apiError->getApiCode() >= 400 && $apiError->getApiCode() < 500 && $apiError->getApiCode() !== 408 && $apiError->getErrorCode() !== null && $apiError->getErrorCode() >= 10000 ? $apiError : null;
                    throw $apiError;
                }
                if (!$commit) {
                    throw new JetStreamApiException(0, 'Expected zero-byte intermediate acknowledgement');
                }
                $fields = new MetadataFields($raw, 'atomic_ack');
                $seq    = $fields->decimal('seq');
                if ($fields->text('stream') !== $stream || $fields->text('batch') !== $id || $fields->decimal('count') !== (string) $count || $seq === null || $seq === '0') {
                    throw new JetStreamApiException(0, 'Invalid atomic PubAck stream, batch, count or seq');
                }
                if (strlen($seq) < strlen((string) $count) || (strlen($seq) === strlen((string) $count) && strcmp($seq, (string) $count) < 0)) {
                    throw new JetStreamApiException(0, 'Invalid atomic PubAck sequence below count');
                }
                $ack = new AtomicBatchAck($stream, $id, $count, $seq, $raw);
                $this->logger->debug('js.atomic.committed', ['batch' => $id, 'count' => $count, 'seq' => $seq]);
                return $ack;
            } catch (Throwable $error) {
                $attempted = $error instanceof ConnectionBoundRequestException ? $error->wasAttempted() : $entered;
                $failure   = new AtomicBatchException($id, $commit && $attempted && $error !== $serverError, $error);
                $this->logger->warning('js.atomic.failed', ['batch' => $id, 'outcome_unknown' => $failure->isOutcomeUnknown(), 'code' => $failure->getApiCode(), 'err_code' => $failure->getErrorCode(), 'error_type' => $error::class]);
                throw $failure;
            }
        }
        throw new \LogicException('Atomic batch did not produce a commit frame');
    }

    /** @return list<array{subject:string,payload:string,headers:array<string,string|list<string>>}> */
    private function snapshot(array $messages): array
    {
        if ($messages === [] || !array_is_list($messages)) {
            throw new InvalidArgumentException('Invalid messages: expected a nonempty ordered list');
        }
        $snapshots = [];
        foreach ($messages as $index => $message) {
            if (!is_array($message) || !isset($message['subject'], $message['payload']) || !is_string($message['subject']) || !is_string($message['payload'])) {
                throw new InvalidArgumentException('Invalid messages[' . $index . ']: expected string subject and payload');
            }
            if (preg_match('/\A[^\s\p{Cc}.*>]+(?:\.[^\s\p{Cc}.*>]+)*\z/u', $message['subject']) !== 1) {
                throw new InvalidArgumentException('Invalid messages[' . $index . '].subject: expected a concrete subject');
            }
            $headers = $message['headers'] ?? [];
            if (!$headers instanceof HeaderBugInterface && !is_array($headers)) {
                throw new InvalidArgumentException('Invalid messages[' . $index . '].headers');
            }
            $values = $headers instanceof HeaderBugInterface ? iterator_to_array($headers) : $headers;
            foreach (array_keys($values) as $name) {
                if (!is_string($name) || preg_match('/\A[!-9;-~]+\z/', $name) !== 1) {
                    throw new InvalidArgumentException('Invalid messages[' . $index . '].headers: invalid header name');
                }
                if (str_starts_with(strtolower($name), 'nats-batch-') || in_array(strtolower($name), ['nats-expected-stream', 'nats-required-api-level'], true)) {
                    throw new InvalidArgumentException('Invalid messages[' . $index . '].headers: reserved header conflict');
                }
            }
            try {
                $copy = new HeaderBag($values);
            } catch (InvalidArgumentException | \TypeError $error) {
                throw new InvalidArgumentException('Invalid messages[' . $index . '].headers', 0, $error);
            }
            $snapshots[] = ['subject' => $message['subject'], 'payload' => $message['payload'], 'headers' => iterator_to_array($copy)];
        }
        return $snapshots;
    }
}
