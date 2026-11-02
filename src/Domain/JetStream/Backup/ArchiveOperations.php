<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Backup;

use Amp\ByteStream\{ReadableStream,WritableStream};
use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundProtocolClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Capability\{Feature,JetStreamCapabilitiesProviderInterface,Support};
use Dorpmaster\Nats\Domain\JetStream\Exception\{JetStreamApiException,JetStreamArchiveException};
use Dorpmaster\Nats\Domain\JetStream\Internal\{Decimal,MetadataFields,ResourceName};
use Dorpmaster\Nats\Domain\JetStream\Model\{JsonObject,StreamInfo};
use Dorpmaster\Nats\Domain\JetStream\Transport\{JetStreamControlPlaneTransportInterface,JetStreamRawPubMessage};
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

/** @internal Server owns archive encoding; the driver only moves bounded opaque chunks. */
final readonly class ArchiveOperations
{
    /** @param \Closure(JsonObject):StreamInfo $streamMapper */
    public function __construct(private JetStreamControlPlaneTransportInterface $transport, private LoggerInterface $logger, private \Closure $streamMapper)
    {
    }
    /** @return array{ArchiveSession,string} */
    private function admit(ArchiveDeadline $deadline): array
    {
        if (!$this->transport instanceof JetStreamCapabilitiesProviderInterface || !($client = $this->transport->getClient()) instanceof ConnectionBoundProtocolClientInterface) {
            throw new JetStreamApiException(0, 'V2 archives require current target capabilities and ConnectionBoundProtocolClientInterface');
        }
        $caps = $this->transport->getCapabilities();
        if ($caps->check(Feature::STREAM_BACKUP_V2)->support === Support::UNSUPPORTED) {
            throw new JetStreamApiException(0, 'V2 archives require JetStream API level 5');
        }
        $connection = $caps->server;
        if (!$connection->isCurrentConnection() || $connection->connectionId === null || $connection->maxPayload === null || $connection->maxPayload <= 0) {
            throw new JetStreamApiException(0, 'V2 archive target connection/max_payload is not established');
        }
        $session = new ArchiveSession($client, $connection, $deadline);
        try {
            $raw   = $session->json($session->request($caps->apiPrefix . '.INFO', '{}', true), 'account_info_response');
            $api   = (new MetadataFields($raw, 'account'))->object('api');
            $level = $api === null ? null : (new MetadataFields($api, 'api'))->decimal('level');
            if ($level === null || Decimal::compare($level, '5') < 0) {
                throw new JetStreamApiException(0, 'Target API level 5 for V2 archives is not established');
            }
            return [$session, $caps->apiPrefix];
        } catch (Throwable $error) {
            $session->close();
            throw $error;
        }
    }
    public function backup(string $stream, WritableStream $output, BackupOptions $options, mixed $timeoutMs, Cancellation|null $cancellation): StreamBackupMetadata
    {
        ResourceName::validate($stream, 'stream');
        if (!$output->isWritable()) {
            throw new InvalidArgumentException('Invalid output: stream is not writable');
        }
        $deadline  = new ArchiveDeadline($timeoutMs, $cancellation);
        $session   = null;
        $bytes     = 0;
        $ioStarted = false;
        $inbox     = null;
        try {
            [$session,$prefix] = $this->admit($deadline);
            $deliver           = ArchiveSession::inbox();
            $inbox             = new ArchiveInbox($options->windowSize + $options->chunkSize, intdiv($options->windowSize, $options->chunkSize) + 3);
            $channel           = $session->channel($deliver, $inbox);
            $body              = JsonObject::fromArray(['deliver_subject' => $deliver, 'no_consumers' => !$options->includeConsumers, 'chunk_size' => $options->chunkSize, 'window_size' => $options->windowSize, 'jsck' => $options->checkMessages]);
            $raw               = $session->json($session->request($prefix . '.STREAM.SNAPSHOT.' . $stream, $body->toJson(), true), 'stream_snapshot_response');
            $fields            = new MetadataFields($raw, 'snapshot');
            $config            = $fields->object('config');
            $state             = $fields->object('state');
            if ($config === null || $state === null || (new MetadataFields($config, 'config'))->text('name') !== $stream) {
                throw new JetStreamApiException(0, 'Invalid snapshot config/state/stream identity');
            }
            // Validate known metadata types before handing any archive bytes to the sink.
            StreamBackupMetadata::completed($stream, $config, $state, 1);
            $index = 1;
            $full  = false;
            $tail  = false;
            while (true) {
                $deadline->remaining();
                $message = $inbox->next($session->scope);
                $data    = $message->getPayload();
                $status  = $message instanceof StatusMessageInterface ? $message->getStatus() : null;
                $reply   = $message->getReplyTo();
                if ($data === '') {
                    if ($reply !== null || $bytes === 0 || ($status === null ? $full : $status->code !== 204 || !$full)) {
                        throw new JetStreamApiException($status?->code ?? 0, $status?->description ?: 'Invalid or missing successful snapshot terminator');
                    }
                    return StreamBackupMetadata::completed($stream, $config, $state, $bytes);
                }
                if ($status !== null || $tail || strlen($data) > $options->chunkSize) {
                    throw new JetStreamApiException($status?->code ?? 0, $status?->description ?: 'Invalid snapshot data chunk');
                }
                if ($reply !== null) {
                    $parts = explode('.', $reply);
                    if (count($parts) !== 7 || implode('.', array_slice($parts, 0, 4)) !== '$JS.SNAPSHOT.ACK.' . $stream || $parts[4] === '' || $parts[5] !== (string) strlen($data) || $parts[6] !== (string) $index || strlen($data) !== $options->chunkSize) {
                        throw new JetStreamApiException(0, 'Invalid snapshot ACK subject/sequence/size');
                    }
                    ResourceName::validate($parts[4], 'snapshot ACK token');
                    $full = true;
                    $index++;
                } elseif (strlen($data) >= $options->chunkSize) {
                    throw new JetStreamApiException(0, 'Full snapshot chunk is missing its ACK subject');
                } else {
                    $tail = true;
                }
                $ioStarted = true;
                $write     = async(static fn () => $output->write($data));
                try {
                    $write->await($session->scope);
                } finally {
                    $write->ignore();
                }
                $bytes += strlen($data);
                if ($reply !== null) {
                    $deadline->remaining();
                    $channel->sendOnce(new JetStreamRawPubMessage($reply, ''), static function (): void {
                    });
                }
            }
        } catch (Throwable $error) {
            if ($ioStarted) {
                try {
                    $output->close();
                } catch (Throwable) {
                }
            }
            $cause = $session?->failure ?? $error;
            $this->logger->warning('js.backup.failed', ['stream' => $stream, 'bytes_completed' => $bytes, 'error_type' => $cause::class]);
            throw new JetStreamArchiveException('backup', $stream, $bytes, false, $ioStarted, $cause);
        } finally {
            $inbox?->close();
            $session?->close();
            $deadline->close();
        }
    }
    public function restore(string $destination, ReadableStream $input, StreamBackupMetadata $backup, string $storage, RestoreOptions $options, mixed $timeoutMs, Cancellation|null $cancellation): StreamInfo
    {
        ResourceName::validate($destination, 'destination');
        if (!in_array($storage, ['file','memory'], true)) {
            throw new InvalidArgumentException('Invalid storage: expected file or memory');
        }
        if (!$input->isReadable()) {
            throw new InvalidArgumentException('Invalid input: stream is not readable');
        }
        $deadline        = new ArchiveDeadline($timeoutMs, $cancellation);
        $config          = $backup->config->withFields(['name' => $destination, 'storage' => $storage]);
        $payload         = '{"config":' . $config->toJson() . ',"state":' . $backup->state->toJson() . '}';
        $session         = null;
        $bytes           = 0;
        $attempted       = false;
        $uploadAttempted = false;
        $ioStarted       = false;
        try {
            [$session,$prefix] = $this->admit($deadline);
            $lifeline          = new ArchiveInbox(0, 1);
            $session->channel(ArchiveSession::inbox(), $lifeline);
            try {
                $reply = $session->request($prefix . '.STREAM.RESTORE.' . $destination, $payload, true);
            } finally {
                $attempted = $session->lastAttempted;
            }
            $raw          = $session->json($reply, 'stream_restore_response');
            $upload       = (new MetadataFields($raw, 'restore'))->text('deliver_subject') ?? '';
            $uploadPrefix = '$JS.SNAPSHOT.RESTORE.' . $destination . '.';
            if (!str_starts_with($upload, $uploadPrefix)) {
                throw new JetStreamApiException(0, 'Invalid restore deliver_subject');
            }
            ResourceName::validate(substr($upload, strlen($uploadPrefix)), 'restore upload token');
            $chunkSize = min($options->chunkSize, $session->connection->maxPayload);
            while (true) {
                $deadline->remaining();
                $ioStarted = true;
                $read      = async(static fn () => $input->read($session->scope));
                try {
                    $data = $read->await($session->scope);
                } finally {
                    $read->ignore();
                }
                if ($data === null) {
                    break;
                }
                if (strlen($data) > $options->maxSourceChunkSize || strlen($data) > $backup->archiveBytes - $bytes) {
                    throw new JetStreamApiException(0, 'Archive source chunk/length exceeds its declared bound');
                }
                for ($offset = 0; $offset < strlen($data); $offset += $chunkSize) {
                    $chunk = substr($data, $offset, $chunkSize);
                    try {
                        $response = $session->request($upload, $chunk);
                    } finally {
                        $uploadAttempted = $uploadAttempted || $session->lastAttempted;
                    }
                    if ($response->getPayload() !== '' || ($response instanceof StatusMessageInterface && $response->getStatus() !== null)) {
                        // A server error uses the final stream-create envelope even during upload.
                        $session->json($response, 'stream_create_response');
                        throw new JetStreamApiException(0, 'Expected empty restore chunk acknowledgement');
                    }
                    $bytes += strlen($chunk);
                }
            }
            if ($bytes !== $backup->archiveBytes) {
                throw new JetStreamApiException(0, 'Truncated archive: source length does not match completed backup');
            }
            try {
                $final = $session->request($upload, '');
            } finally {
                $uploadAttempted = $uploadAttempted || $session->lastAttempted;
            }
            $raw  = $session->json($final, 'stream_create_response');
            $info = ($this->streamMapper)($raw);
            if ($info->getName() !== $destination || $info->getConfiguration()?->get('storage') !== $storage) {
                throw new JetStreamApiException(0, 'Restore final stream identity/storage mismatch');
            }
            return $info;
        } catch (Throwable $error) {
            if ($ioStarted) {
                try {
                    $input->close();
                } catch (Throwable) {
                }
            }
            $cause   = $session?->failure ?? $error;
            $unknown = ($attempted || $uploadAttempted) && !($session?->confirmedFailure ?? false);
            $this->logger->warning('js.restore.failed', ['stream' => $destination, 'bytes_completed' => $bytes, 'outcome_unknown' => $unknown, 'error_type' => $cause::class]);
            throw new JetStreamArchiveException('restore', $destination, $bytes, $unknown, $uploadAttempted || $unknown, $cause);
        } finally {
            $session?->close();
            $deadline->close();
        }
    }
}
