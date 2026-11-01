<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\AtomicBatchException;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\AtomicBatchTargetStubClient;
use Dorpmaster\Nats\Tests\Support\AtomicBatchTopologyResponses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtomicBatchTopologyTest extends TestCase
{
    private function client(): ClientInterface&ConnectionBoundRequestClientInterface&ServerInformationProviderInterface
    {
        $client = $this->createMockForIntersectionOfInterfaces([ClientInterface::class, ConnectionBoundRequestClientInterface::class, ServerInformationProviderInterface::class]);
        $client->method('getServerInformation')->willReturn(new ServerInformation('local', '2.15.2', true, 5, 1, static fn (): bool => true));
        return $client;
    }

    public static function unsafe(): iterable
    {
        foreach (['orders.a', 'orders.*', 'orders.>', '>'] as $pattern) {
            yield 'mapping ' . $pattern => ['ACCOUNTZ', ['account_detail' => ['mappings' => [$pattern => [['subject' => 'wire.a', 'weight' => 100]]]]]];
        }
        foreach (['imports', 'exports'] as $kind) {
            yield $kind => ['ACCOUNTZ', ['account_detail' => [$kind => [['subject' => 'orders.>', 'local_subject' => 'orders.>']]]]];
        }
        yield 'missing import local pattern' => ['ACCOUNTZ', ['account_detail' => ['imports' => [['subject' => 'wire.>']]]]];
        yield 'incomplete account' => ['ACCOUNTZ', ['account_detail' => ['complete' => false]]];
        yield 'foreign account' => ['ACCOUNTZ', ['account_detail' => ['account_name' => 'OTHER']]];
        yield 'active account leaf' => ['ACCOUNTZ', ['account_detail' => ['leafnode_connections' => 1]]];
        yield 'configured leaf' => ['VARZ', ['leaf' => ['port' => 7422]]];
        yield 'leaf remote' => ['VARZ', ['leaf' => ['remotes' => [['urls' => ['nats://old:7422']]]]]];
        yield 'active leaf' => ['VARZ', ['leafnodes' => 1]];
        yield 'gateway' => ['VARZ', ['gateway' => ['port' => 7522]]];
        yield 'no api level' => ['VARZ', ['jetstream' => ['stats' => ['api' => ['level' => null]]]]];
        yield 'old api level' => ['VARZ', ['jetstream' => ['stats' => ['api' => ['level' => 1]]]]];
        yield 'partial route inventory' => ['VARZ', ['routes' => 1]];
        yield 'unverified route peer' => ['ROUTEZ', ['routes' => [[]], 'num_routes' => 1]];
        yield 'missing monitor data' => ['DENIED', []];
        yield 'wrong monitor server' => ['IDENTITY', []];
        yield 'mapping on another cluster node' => ['PEER', []];
    }

    #[DataProvider('unsafe')]
    public function testPublicPublisherRejectsUnverifiedTopologyBeforeAnyBatchFrame(string $kind, array $change): void
    {
        $client = $this->client();
        $client->expects(self::never())->method('requestOnceOnConnection');
        $stub = new AtomicBatchTargetStubClient($client, static function ($message) use ($kind, $change): string|null {
            $subject = $message->getSubject();
            if (!str_starts_with($subject, '$SYS.')) {
                return null;
            }
            $id   = str_contains($subject, '.peer.') ? 'peer' : 'local';
            $json = AtomicBatchTopologyResponses::payload($subject, $id);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if ($kind === 'DENIED') {
                return '{"error":{"code":503,"description":"monitoring unavailable"}}';
            }
            if ($kind === 'IDENTITY') {
                $data['server']['id'] = 'OTHER';
            } elseif ($kind === 'PEER') {
                if (str_ends_with($subject, '.VARZ')) {
                    $data['data']['routes'] = 1;
                } elseif (str_ends_with($subject, '.ROUTEZ')) {
                    $data['data']['num_routes'] = 1;
                    $data['data']['routes']     = [['remote_id' => $id === 'local' ? 'peer' : 'local']];
                } elseif ($id === 'peer' && str_ends_with($subject, '.ACCOUNTZ')) {
                    $data['data']['account_detail']['mappings'] = ['orders.a' => [['subject' => 'wire.a']]];
                }
            } elseif (str_ends_with($subject, '.' . $kind)) {
                $data['data'] = array_replace_recursive($data['data'], $change);
            }
            return json_encode($data, JSON_THROW_ON_ERROR);
        });
        try {
            (new JetStreamPublisher($stub))->publishAtomicBatch('ORDERS', [['subject' => 'orders.a', 'payload' => 'first'], ['subject' => 'orders.b', 'payload' => 'second']]);
            self::fail('An uncertain route must never carry batch data');
        } catch (AtomicBatchException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertNotNull($error->getPrevious());
            self::assertNotEmpty($error->getBatchId());
        }
        self::assertNotEmpty($stub->checks);
    }

    public static function numericMappingNames(): iterable
    {
        $target = '[{"subject":"wire.a","weight":100}]';
        foreach (['unused.literal', '123', '0', '00'] as $name) {
            yield 'unrelated ' . $name => ['{' . json_encode($name) . ':' . $target . '}', 'orders.a', false];
        }
        foreach (['123', '0', '00', '9223372036854775807', '9223372036854775808'] as $name) {
            yield 'matching ' . $name => ['{' . json_encode($name) . ':' . $target . '}', $name, true];
        }
        yield '0 does not match 00' => ['{"0":' . $target . '}', '00', false];
        yield '00 does not match 0' => ['{"00":' . $target . '}', '0', false];
        foreach (['0', '00', 'orders.a'] as $subject) {
            yield 'both names against ' . $subject => ['{"0":' . $target . ',"00":' . $target . '}', $subject, $subject !== 'orders.a'];
        }
    }

    #[DataProvider('numericMappingNames')]
    public function testPublicPublisherPreservesExactJsonMappingNames(string $mappings, string $subject, bool $matching): void
    {
        $client = $this->client();
        if ($matching) {
            $client->expects(self::never())->method('requestOnceOnConnection');
        } else {
            $client->expects(self::once())->method('requestOnceOnConnection')->willReturnCallback(static fn ($message): MsgMessage => new MsgMessage('ack', '1', '{"stream":"ORDERS","batch":"' . $message->getHeaders()->get('Nats-Batch-Id') . '","count":1,"seq":1}'));
        }
        $publisher = $this->withRawMappings($client, $mappings);
        try {
            $ack = $publisher->publishAtomicBatch('ORDERS', [['subject' => $subject, 'payload' => 'first']]);
            self::assertFalse($matching);
            self::assertSame('1', $ack->getSeq());
        } catch (AtomicBatchException $error) {
            self::assertTrue($matching);
            self::assertFalse($error->isOutcomeUnknown());
            self::assertStringContainsString('subject mapping may redirect or copy', $error->getDescription());
        }
    }

    public static function malformedMappingObjects(): iterable
    {
        foreach (['null', '[]', '123', '"123"', 'false', '{"123":[],"123":[]}'] as $json) {
            yield [$json];
        }
    }

    #[DataProvider('malformedMappingObjects')]
    public function testInvalidMappingObjectStillPreventsAllBatchFrames(string $mappings): void
    {
        $client = $this->client();
        $client->expects(self::never())->method('requestOnceOnConnection');
        try {
            $this->withRawMappings($client, $mappings)->publishAtomicBatch('ORDERS', [['subject' => 'orders.a', 'payload' => 'first']]);
            self::fail('Malformed mappings cannot certify the route');
        } catch (AtomicBatchException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertNotNull($error->getPrevious());
        }
    }

    private function withRawMappings(ClientInterface&ConnectionBoundRequestClientInterface&ServerInformationProviderInterface $client, string $mappings): JetStreamPublisher
    {
        return new JetStreamPublisher(new AtomicBatchTargetStubClient($client, static function ($message) use ($mappings): string|null {
            if (!str_ends_with($message->getSubject(), '.ACCOUNTZ')) {
                return null;
            }
            return '{"server":{"id":"local"},"data":{"server_id":"local","account_detail":{"account_name":"HUB","complete":true,"expired":false,"leafnode_connections":0,"mappings":' . $mappings . '}}}';
        }));
    }

    public function testGenericJsonTimeoutAfterCommitRetainsUncertaintyCodeAndCause(): void
    {
        $client = $this->client();
        $client->expects(self::once())->method('requestOnceOnConnection')->willReturn(new MsgMessage('ack', '1', '{"error":{"code":408,"description":"Request Timeout"}}'));
        try {
            (new JetStreamPublisher(new AtomicBatchTargetStubClient($client)))->publishAtomicBatch('ORDERS', [['subject' => 'orders.a', 'payload' => 'first']]);
            self::fail('A generic timeout does not confirm rejection');
        } catch (AtomicBatchException $error) {
            self::assertTrue($error->isOutcomeUnknown());
            self::assertSame(408, $error->getApiCode());
            self::assertNull($error->getErrorCode());
            self::assertSame('Request Timeout', $error->getPrevious()->getMessage());
            self::assertStringContainsString($error->getBatchId(), $error->getMessage());
        }
    }
}
