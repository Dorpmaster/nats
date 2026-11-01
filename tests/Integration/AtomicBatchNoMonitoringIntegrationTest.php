<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\JetStream\Exception\AtomicBatchException;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\AtomicBatchFixture;
use PHPUnit\Framework\TestCase;

final class AtomicBatchNoMonitoringIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public function testMissingDiagnosticPermissionRejectsBeforeBatch(): void
    {
        if (getenv('NATS_ATOMIC_NO_MONITORING') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-atomic for the restricted-access server.');
        }
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $fixture = new AtomicBatchFixture();
            try {
                $started = hrtime(true);
                try {
                    $fixture->publisher->publishAtomicBatch($fixture->stream, [['subject' => $fixture->subject . '.a', 'payload' => 'first'], ['subject' => $fixture->subject . '.b', 'payload' => 'second']], timeoutMs: 500);
                    self::fail('Missing monitoring rights must never enable an unchecked batch');
                } catch (AtomicBatchException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertNotNull($error->getPrevious());
                }
                self::assertLessThan(2.0, (hrtime(true) - $started) / 1_000_000_000);
                self::assertSame([], $fixture->probe->records);
                self::assertSame([], $fixture->read());
                self::assertSame('0', $fixture->writer->admin->getStreamInfo($fixture->stream)->getState()->messages);
                $directory = getenv('NATS_ATOMIC_TRACE_DIR');
                if ($directory) {
                    file_put_contents($directory . '/no-monitoring.json', json_encode(['checks' => $fixture->probe->checks, 'frames' => $fixture->probe->records, 'stored' => 0, 'outcome_unknown' => false, 'next_request_success' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                }
            } finally {
                $fixture->close();
            }
        });
    }
}
