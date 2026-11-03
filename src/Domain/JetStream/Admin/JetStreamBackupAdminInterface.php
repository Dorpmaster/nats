<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Amp\ByteStream\{ReadableStream,WritableStream};
use Amp\Cancellation;
use Dorpmaster\Nats\Domain\JetStream\Backup\{BackupOptions,RestoreOptions,StreamBackupMetadata};
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;

/** Optional streaming archive API; existing Admin implementations remain compatible. */
interface JetStreamBackupAdminInterface extends JetStreamAdminInterface
{
    public function backupStream(string $stream, WritableStream $output, BackupOptions|null $options = null, int|null $timeoutMs = null, Cancellation|null $cancellation = null): StreamBackupMetadata;
    public function restoreStream(string $destination, ReadableStream $input, StreamBackupMetadata $backup, string $storage, RestoreOptions|null $options = null, int|null $timeoutMs = null, Cancellation|null $cancellation = null): StreamInfo;
}
