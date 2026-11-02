<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\AtomicBatchException;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchCommitMode;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\MessageStatus;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\AtomicBatchTargetStubClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtomicBatchTargetTest extends TestCase
{
    private function client(): ClientInterface&ConnectionBoundRequestClientInterface&ServerInformationProviderInterface
    {
        $client = $this->createMockForIntersectionOfInterfaces([ClientInterface::class, ConnectionBoundRequestClientInterface::class, ServerInformationProviderInterface::class]);
        $client->method('getServerInformation')->willReturn(new ServerInformation('local', '2.15.2', true, 5, 1, static fn (): bool => true));
        return $client;
    }

    public static function invalidTargets(): iterable
    {
        $config = ['name' => 'ORDERS', 'allow_atomic' => true, 'metadata' => ['_nats.level' => '5']];
        foreach ([null, [], ['_nats.level' => '1'], ['_nats.level' => '3'], ['_nats.level' => 5], ['_nats.level' => '5.0'], ['_nats.level' => '5\n'], ['_nats.level' => '999999999999999999']] as $metadata) {
            yield 'metadata ' . json_encode($metadata) => [array_replace($config, ['metadata' => $metadata]), null];
        }
        yield 'foreign stream' => [array_replace($config, ['name' => 'OTHER']), null];
        yield 'disabled atomic' => [array_replace($config, ['allow_atomic' => false]), null];
        yield 'no subject route' => [$config, ['total' => 0, 'streams' => []]];
        yield 'foreign subject route' => [$config, ['total' => 1, 'streams' => ['OTHER']]];
        yield 'ambiguous subject route' => [$config, ['total' => 2, 'streams' => ['ORDERS', 'OTHER']]];
        yield 'unvalidated total' => [$config, ['total' => '1', 'streams' => ['ORDERS']]];
    }

    #[DataProvider('invalidTargets')]
    public function testCurrentLocalInfoCannotAuthorizeUnconfirmedTarget(array $config, array|null $names): void
    {
        $client = $this->client();
        $client->expects(self::never())->method('requestOnceOnConnection');
        $stub = new AtomicBatchTargetStubClient($client, static fn ($message): string => json_encode(str_contains($message->getSubject(), '.INFO.')
            ? ['type' => 'io.nats.jetstream.api.v1.stream_info_response', 'config' => $config]
            : ['type' => 'io.nats.jetstream.api.v1.stream_names_response'] + $names, JSON_THROW_ON_ERROR));
        try {
            (new JetStreamPublisher($stub))->publishAtomicBatch('ORDERS', [['subject' => 'orders.a', 'payload' => 'first'], ['subject' => 'orders.b', 'payload' => 'second']], new AtomicBatchOptions(AtomicBatchCommitMode::EOB));
            self::fail('No batch frame may be sent');
        } catch (AtomicBatchException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertNotNull($error->getPrevious());
        }
        self::assertNotEmpty($stub->checks);
    }

    public function testAllSubjectsAreCheckedBeforeFirstFrame(): void
    {
        $client = $this->client();
        $client->expects(self::never())->method('requestOnceOnConnection');
        $bag  = new HeaderBag(['X' => 'before']);
        $stub = new AtomicBatchTargetStubClient($client, static function ($message) use ($bag): string {
            $bag->set('X', 'after');
            if (str_contains($message->getSubject(), '.INFO.')) {
                return '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"ORDERS","allow_atomic":true,"metadata":{"_nats.level":"5"}}}';
            }
            return str_contains($message->getPayload(), 'orders.b')
                ? '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":0,"streams":[]}'
                : '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":1,"streams":["ORDERS"]}';
        });
        try {
            (new JetStreamPublisher($stub))->publishAtomicBatch('ORDERS', [['subject' => 'orders.a', 'payload' => 'first', 'headers' => $bag], ['subject' => 'orders.b', 'payload' => 'second']]);
            self::fail('Later subject must not authorize the first frame');
        } catch (AtomicBatchException $error) {
            self::assertStringContainsString('route', $error->getDescription());
        }
        self::assertCount(3, $stub->checks);
    }

    public function testHeadersAreFrozenBeforeTargetReadsAndReadsConsumeTheDeadline(): void
    {
        $client = $this->client();
        $bag    = new HeaderBag(['X' => 'before']);
        $client->expects(self::once())->method('requestOnceOnConnection')->willReturnCallback(static function ($message, int $connection, float $timeout): MsgMessage {
            self::assertSame('before', $message->getHeaders()->get('X'));
            self::assertLessThan(0.18, $timeout);
            return new MsgMessage('ack', '1', '{"stream":"ORDERS","batch":"' . $message->getHeaders()->get('Nats-Batch-Id') . '","count":1,"seq":1}');
        });
        $stub = new AtomicBatchTargetStubClient($client, static function ($message) use ($bag) {
            $bag->set('X', 'after');
            if (str_starts_with($message->getSubject(), '$JS.API.')) {
                \Amp\delay(0.03);
            }
            return null;
        });
        $ack  = (new JetStreamPublisher($stub))->publishAtomicBatch('ORDERS', [['subject' => 'orders.a', 'payload' => 'first', 'headers' => $bag]], timeoutMs: 200);
        self::assertSame('1', $ack->getSeq());
        self::assertCount(6, $stub->checks);
    }

    public static function gatewayStatuses(): iterable
    {
        foreach ([400, 408, 500, 502, 503, 504] as $code) {
            yield [$code];
        }
    }

    #[DataProvider('gatewayStatuses')]
    public function testStatusOnlyIsNotAnAuthoritativeCommitRejection(int $code): void
    {
        $client = $this->client();
        $client->expects(self::once())->method('requestOnceOnConnection')->willReturn(new HMsgMessage('ack', '1', '', new HeaderBag(), status: new MessageStatus($code, 'Synthetic intermediary status')));
        try {
            (new JetStreamPublisher(new AtomicBatchTargetStubClient($client)))->publishAtomicBatch('ORDERS', [['subject' => 'orders.a', 'payload' => 'first']]);
            self::fail('Expected ambiguous commit');
        } catch (AtomicBatchException $error) {
            self::assertTrue($error->isOutcomeUnknown());
            self::assertSame($code, $error->getApiCode());
            self::assertSame($code, $error->getPrevious()->getApiCode());
            self::assertSame(32, strlen($error->getBatchId()));
        }
    }
}
