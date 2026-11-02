<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\FastBatchFixture;
use PHPUnit\Framework\TestCase;

final class FastBatchNoMonitoringIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public function testMissingDiagnosticPermissionRejectsBeforeBatch(): void
    {
        if (getenv('NATS_FAST_NO_MONITORING') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-fast for the restricted-access server.');
        }
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $fixture = new FastBatchFixture();
            try {
                $started = hrtime(true);
                try {
                    (new \Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher($fixture->probe))->openFastBatch($fixture->stream, $fixture->subjects, timeoutMs: 500);
                    self::fail('Missing monitoring rights must never enable an unchecked batch');
                } catch (\Throwable $error) {
                    self::assertInstanceOf(\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException::class, $error);
                }
                self::assertLessThan(2.0, (hrtime(true) - $started) / 1_000_000_000);
                self::assertSame([], $fixture->probe->records);
                self::assertSame([], $fixture->read());
                self::assertSame('0', $fixture->admin->getStreamInfo($fixture->stream)->getState()->messages);
                $directory = getenv('NATS_FAST_TRACE_DIR');
                if ($directory) {
                    file_put_contents($directory . '/no-monitoring.json', json_encode(['checks' => $fixture->probe->checks, 'frames' => $fixture->probe->records, 'stored' => 0, 'outcome_unknown' => false, 'next_request_success' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                }
            } finally {
                $fixture->close();
            }
        });
    }
}
