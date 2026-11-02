<?php

// Deliberately a separate PHP process: only persisted archive/config/state cross the boundary.
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Amp\ByteStream\ReadableResourceStream;
use Dorpmaster\Nats\Domain\JetStream\Backup\StreamBackupMetadata;
use Dorpmaster\Nats\Tests\Support\JetStreamPlacementFixture;

$fixture = new JetStreamPlacementFixture();
try {
    // connect() starts an asynchronous handshake; this read is the READY barrier.
    $fixture->transport->requestJson('INFO', new \Dorpmaster\Nats\Domain\JetStream\Model\JsonObject('{}'));
    $metadata = StreamBackupMetadata::fromJson(file_get_contents($argv[4]));
    $source   = new ReadableResourceStream(fopen($argv[3], 'rb'), 65536);
    $info     = $fixture->admin->restoreStream($argv[1], $source, $metadata, $argv[2], timeoutMs: 15000);
    fwrite(STDOUT, $info->getResponse()->toJson());
    $source->close();
} finally {
    $fixture->close();
}
