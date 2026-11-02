<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Admin;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\SingleAttemptRequestClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdminInterface;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamResettingAdminInterface;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilitiesProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\RecordingLogger;
use Dorpmaster\Nats\Tests\Support\WeakConsumerResetCaller;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamConsumerResetTest extends TestCase
{
    private const string RESPONSE = '{"type":"io.nats.jetstream.api.v1.consumer_reset_response","stream_name":"S","name":"C","config":{"durable_name":"C","metadata":{"nested":{"x":"original"}}},"reset_seq":9,"delivered":{"consumer_seq":0,"stream_seq":8},"ack_floor":{"consumer_seq":0,"stream_seq":8},"num_pending":18446744073709551615}';

    public static function sequences(): iterable
    {
        foreach ([null, 0, '0', '000'] as $value) {
            yield [$value, '{}'];
        }
        foreach ([7, '7', '0007'] as $value) {
            yield [$value, '{"seq":7}'];
        }
        foreach ([(string) PHP_INT_MAX, '9223372036854775808', '18446744073709551615'] as $value) {
            yield [$value, '{"seq":' . $value . '}'];
        }
    }

    #[DataProvider('sequences')]
    public function testExactRequestAndAuthoritativeResponse(int|string|null $value, string $body): void
    {
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::never())->method('requestJson');
        $transport->expects(self::never())->method('request');
        $transport->expects(self::once())->method('requestJsonOnce')->with('CONSUMER.RESET.S.C', self::callback(static fn (JsonObject $payload): bool => $payload->toJson() === $body), 123)
            ->willReturn(new JsonObject(self::RESPONSE));
        $result = (new JetStreamAdmin($transport))->resetConsumer('S', 'C', $value, 123);
        self::assertSame('9', $result->getResetSequence(), 'Use the server value, never echo the input');
        $info = $result->getConsumerInfo();
        self::assertSame('S', $info->getStreamName());
        self::assertSame('C', $info->getName());
        self::assertSame('18446744073709551615', $info->getPendingCount());
        self::assertSame('0', $info->getDelivered()->consumerSequence);
        self::assertSame(self::RESPONSE, $info->getResponse()->toJson());
        $copy    = $info->getMetadata()->get('nested');
        $copy->x = 'changed';
        self::assertSame('original', $info->getMetadata()->get('nested')->x);
        self::assertInstanceOf(JetStreamResettingAdminInterface::class, new JetStreamAdmin($transport));
        self::assertFalse((new \ReflectionClass(JetStreamAdminInterface::class))->hasMethod('resetConsumer'));
    }

    public static function responsePositions(): iterable
    {
        foreach (['1', '9223372036854775807', '9223372036854775808', '18446744073709551615'] as $sequence) {
            yield [$sequence];
        }
    }

    #[DataProvider('responsePositions')]
    public function testExactServerPositionAcrossUInt64Range(string $sequence): void
    {
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJsonOnce')->willReturn(new JsonObject(str_replace('"reset_seq":9', '"reset_seq":' . $sequence, self::RESPONSE)));
        self::assertSame($sequence, (new JetStreamAdmin($transport))->resetConsumer('S', 'C')->getResetSequence());
    }

    public static function invalidNativeTypes(): iterable
    {
        foreach ([false, true] as $weak) {
            foreach ([1.5, 1.0, true, false, [], new \stdClass()] as $value) {
                yield [$weak, $value];
            }
        }
    }

    #[DataProvider('invalidNativeTypes')]
    public function testNativeSequenceTypesFailBeforeEffects(bool $weak, mixed $sequence): void
    {
        $transport = $this->createMockForIntersectionOfInterfaces([JetStreamSingleAttemptTransportInterface::class, JetStreamCapabilitiesProviderInterface::class]);
        $transport->expects(self::never())->method('getCapabilities');
        $transport->expects(self::never())->method('getClient');
        $transport->expects(self::never())->method('getSubscriptionIdHelper');
        $transport->expects(self::never())->method('requestJsonOnce');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('streamSequence');
        $admin = new JetStreamAdmin($transport);
        if ($weak) {
            WeakConsumerResetCaller::reset($admin, 'S', 'C', $sequence);
        } else {
            $admin->resetConsumer('S', 'C', $sequence);
        }
    }

    public static function validCallerValues(): iterable
    {
        foreach ([false, true] as $weak) {
            foreach ([[null, '{}'], [0, '{}'], ['0002', '{"seq":2}'], ['18446744073709551615', '{"seq":18446744073709551615}']] as [$value, $body]) {
                yield [$weak, $value, $body];
            }
        }
    }

    #[DataProvider('validCallerValues')]
    public function testCallerModesPreserveExactValidBodies(bool $weak, mixed $sequence, string $body): void
    {
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJsonOnce')->with('CONSUMER.RESET.S.C', self::callback(static fn (JsonObject $payload): bool => $payload->toJson() === $body), 2000)->willReturn(new JsonObject(self::RESPONSE));
        $admin  = new JetStreamAdmin($transport);
        $result = $weak ? WeakConsumerResetCaller::reset($admin, 'S', 'C', $sequence) : $admin->resetConsumer('S', 'C', $sequence);
        self::assertSame('9', $result->getResetSequence());
    }

    public static function configNames(): iterable
    {
        yield 'absent' => [null, true, 'C'];
        yield 'matching' => ['"C"', true, 'C'];
        foreach (['"OTHER"', '42', '9223372036854775808', '18446744073709551615', 'null', 'true', '[]', '{}'] as $token) {
            yield $token => [$token, false, 'C'];
        }
        foreach (['42', '9223372036854775808', '18446744073709551615'] as $consumer) {
            yield 'quoted matching digits ' . $consumer => ['"' . $consumer . '"', true, $consumer];
            yield 'unquoted matching digits ' . $consumer => [$consumer, false, $consumer];
        }
    }

    #[DataProvider('configNames')]
    public function testConfigNameMustAgreeAfterOneAttempt(string|null $token, bool $valid, string $consumer): void
    {
        $name      = json_encode($consumer, JSON_THROW_ON_ERROR);
        $json      = str_replace('"C"', $name, self::RESPONSE);
        $json      = $token === null ? $json : str_replace('"durable_name":' . $name, '"durable_name":' . $name . ',"name":' . $token, $json);
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::never())->method('requestJson');
        $transport->expects(self::never())->method('request');
        $transport->expects(self::once())->method('requestJsonOnce')->with('CONSUMER.RESET.S.' . $consumer)->willReturn(new JsonObject($json));
        if (!$valid) {
            $this->expectException(JetStreamApiException::class);
            $this->expectExceptionMessage('config.name');
        }
        $result = (new JetStreamAdmin($transport))->resetConsumer('S', $consumer);
        self::assertSame('9', $result->getResetSequence());
        self::assertSame($json, $result->getConsumerInfo()->getResponse()->toJson());
    }

    public static function invalidArguments(): iterable
    {
        foreach (['', '-1', '+7', '1.5', '1e2', ' 7', '7 ', "7\n", '18446744073709551616', '٧', str_repeat('9', 100)] as $value) {
            yield ['S', 'C', $value, null, 'streamSequence'];
        }
        yield ['S', 'C', -1, null, 'streamSequence'];
        foreach ([0, -1] as $timeout) {
            yield ['S', 'C', 7, $timeout, 'timeoutMs'];
        }
        foreach (['', 'a.b', 'a*b', 'a>b', 'a/b', 'a\\b', 'a b', "a\t", "a\r\n", "a\0"] as $bad) {
            yield [$bad, 'C', 7, null, 'stream'];
            yield ['S', $bad, 7, null, 'consumer'];
        }
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsHaveNoObservableTransportEffects(string $stream, string $consumer, int|string|null $sequence, int|null $timeout, string $field): void
    {
        $transport = $this->createMockForIntersectionOfInterfaces([JetStreamSingleAttemptTransportInterface::class, JetStreamCapabilitiesProviderInterface::class]);
        $transport->expects(self::never())->method('getCapabilities');
        $transport->expects(self::never())->method('requestJsonOnce');
        $transport->expects(self::never())->method('getClient');
        $transport->expects(self::never())->method('getSubscriptionIdHelper');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);
        (new JetStreamAdmin($transport))->resetConsumer($stream, $consumer, $sequence, $timeout);
    }

    public static function capabilityCases(): iterable
    {
        yield 'known old server' => [3, true, '$JS.API', false];
        yield 'supported' => [4, true, '$JS.API', true];
        yield 'future level' => [100, true, '$JS.API', true];
        yield 'missing api level' => [null, true, '$JS.API', true];
        yield 'stale information' => [3, false, '$JS.API', true];
        yield 'domain target unknown' => [3, true, '$JS.EU.API', true];
        yield 'import target unknown' => [3, true, 'IMPORT@EU+API', true];
    }

    #[DataProvider('capabilityCases')]
    public function testCapabilityGateDoesNotProbe(int|null $level, bool $current, string $prefix, bool $attempt): void
    {
        $transport = $this->createMockForIntersectionOfInterfaces([JetStreamSingleAttemptTransportInterface::class, JetStreamCapabilitiesProviderInterface::class]);
        $transport->expects(self::once())->method('getCapabilities')->willReturn(new JetStreamCapabilities(new ServerInformation(apiLevel: $level, isCurrent: static fn (): bool => $current), $prefix));
        $transport->expects(self::never())->method('requestJson');
        if ($attempt) {
            $transport->expects(self::once())->method('requestJsonOnce')->with('CONSUMER.RESET.S.C', self::isInstanceOf(JsonObject::class), 2000)->willReturn(new JsonObject(self::RESPONSE));
            self::assertSame('9', (new JetStreamAdmin($transport))->resetConsumer('S', 'C')->getResetSequence());
        } else {
            $transport->expects(self::never())->method('requestJsonOnce');
            $transport->expects(self::never())->method('getClient');
            $this->expectException(JetStreamApiException::class);
            $this->expectExceptionMessage('unsupported');
            (new JetStreamAdmin($transport))->resetConsumer('S', 'C');
        }
    }

    public static function malformedResponses(): iterable
    {
        foreach (['null', '0', '-1', '"3"', '1.5', '1e2', 'true', '18446744073709551616'] as $value) {
            yield [str_replace('"reset_seq":9', '"reset_seq":' . $value, self::RESPONSE), 'reset_seq'];
        }
        yield [str_replace(',"reset_seq":9', '', self::RESPONSE), 'reset_seq'];
        yield [str_replace('consumer_reset_response', 'consumer_info_response', self::RESPONSE), 'type'];
        yield [str_replace('"type":"io.nats.jetstream.api.v1.consumer_reset_response"', '"type":9223372036854775808', self::RESPONSE), 'type'];
        yield [str_replace('"stream_name":"S"', '"stream_name":"OTHER"', self::RESPONSE), 'identity'];
        yield [str_replace('"name":"C"', '"name":"OTHER"', self::RESPONSE), 'identity'];
        yield [str_replace('"durable_name":"C"', '"durable_name":"OTHER"', self::RESPONSE), 'identity'];
        yield [str_replace('"stream_name":"S",', '', self::RESPONSE), 'identity'];
        yield [str_replace('"num_pending":18446744073709551615', '"num_pending":"4"', self::RESPONSE), 'num_pending'];
    }

    #[DataProvider('malformedResponses')]
    public function testMalformedResponseIsControlled(string $json, string $field): void
    {
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJsonOnce')->willReturn(new JsonObject($json));
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage($field);
        (new JetStreamAdmin($transport))->resetConsumer('S', 'C');
    }

    public function testServerErrorsKeepCodesAndDescriptionWithoutLoggingIt(): void
    {
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJsonOnce')->willReturn(new JsonObject('{"error":{"code":400,"err_code":10204,"description":"invalid reset: synthetic-server-detail"}}'));
        $logs = new RecordingLogger();
        try {
            (new JetStreamAdmin($transport, $logs))->resetConsumer('S', 'C');
            self::fail('Expected server error');
        } catch (JetStreamApiException $error) {
            self::assertSame(400, $error->getApiCode());
            self::assertSame(10204, $error->getErrorCode());
            self::assertSame('invalid reset: synthetic-server-detail', $error->getDescription());
            self::assertStringNotContainsString('synthetic-server-detail', serialize($logs));
        }
    }

    public function testTimeoutPreservesCancellationCause(): void
    {
        $cause     = new CancelledException();
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJsonOnce')->willThrowException($cause);
        try {
            (new JetStreamAdmin($transport))->resetConsumer('S', 'C');
            self::fail('Expected deadline');
        } catch (JetStreamTimeoutException $error) {
            self::assertSame($cause, $error->getPrevious());
        }
    }

    public function testLegacyTransportCannotSilentlyReplayReset(): void
    {
        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->expects(self::never())->method('request');
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage('SingleAttempt');
        (new JetStreamAdmin($transport))->resetConsumer('S', 'C');
    }

    public function testDirectTransportResetUsesRequestOnceAndExactPrefix(): void
    {
        $client = $this->createMockForIntersectionOfInterfaces([ClientInterface::class, SingleAttemptRequestClientInterface::class]);
        $client->expects(self::never())->method('request');
        $client->expects(self::once())->method('requestOnce')->with(self::callback(static function ($message): bool {
            self::assertSame('IMPORT@EU+API.CONSUMER.RESET.ORDERS@EU.WORKER+EU', $message->getSubject());
            self::assertSame('{"seq":9223372036854775808}', $message->getPayload());
            return true;
        }), 0.123)->willReturn(new MsgMessage('reply', '1', self::RESPONSE));
        $transport = new JetStreamControlPlaneTransport($client, apiPrefix: 'IMPORT@EU+API.');
        self::assertSame(self::RESPONSE, $transport->requestJson('CONSUMER.RESET.ORDERS@EU.WORKER+EU', new JsonObject('{"seq":9223372036854775808}'), 123)->toJson());
    }
}
