<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Tests\Support\CapturedProtocolLogs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JetStreamStructuredErrorsTest extends TestCase
{
    public static function errors(): iterable
    {
        $cases = [
            'code and err_code' => ['{"error":{"code":409,"err_code":10071,"description":"synthetic-n1-response-secret"}}', 409, 10071],
            'unknown err_code' => ['{"error":{"code":499,"err_code":987654,"description":"synthetic-n1-response-secret"}}', 499, 987654],
            'missing err_code' => ['{"error":{"code":409,"description":"synthetic-n1-response-secret"}}', 409, null],
            'missing code' => ['{"error":{"err_code":10071}}', null, 10071],
            'empty object' => ['{"error":{}}', null, null],
            'code is object' => ['{"error":{"code":{"secret":"synthetic-n1-response-secret"}}}', null, null],
            'err_code is array' => ['{"error":{"code":409,"err_code":[10071]}}', 409, null],
            'err_code overflows' => ['{"error":{"code":409,"err_code":18446744073709551615}}', 409, null],
            'description is array' => ['{"error":{"code":409,"description":["synthetic-n1-response-secret"]}}', 409, null],
            'wrong error type' => ['{"error":"synthetic-n1-response-secret"}', null, null],
            'null error' => ['{"error":null}', null, null],
            'broken JSON' => ['{"synthetic-n1-response-secret":', null, null],
            'top-level array' => ['[]', null, null],
            'top-level null' => ['null', null, null],
        ];
        foreach (['control', 'publish'] as $route) {
            foreach ($cases as $name => $case) {
                yield $route . '-' . $name => [$route, ...$case];
            }
        }
    }

    #[DataProvider('errors')]
    public function testStructuredErrorsAndMalformedRepliesStayErrorsWithoutLeaking(string $route, string $json, int|null $expectedCode, int|null $expectedError): void
    {
        $client = self::createMock(ClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn(new MsgMessage('reply', '1', $json));
        $logs = new CapturedProtocolLogs();
        try {
            if ($route === 'control') {
                (new JetStreamControlPlaneTransport($client, logger: $logs))->request('STREAM.INFO.S', []);
            } else {
                (new JetStreamPublisher($client, $logs))->publish('orders', 'synthetic-n1-payload-secret');
            }
            self::fail('Malformed/error reply must not be successful');
        } catch (JetStreamApiException $error) {
            self::assertSame($expectedCode ?? ($route === 'control' ? 0 : 500), $error->getApiCode());
            self::assertSame($error->getApiCode(), $error->getCode());
            self::assertSame($expectedError, $error->getErrorCode());
            self::assertSame($error->getDescription(), $error->getMessage());
            if (str_starts_with($json, '{"error":{"code":409,"err_code":10071')) {
                self::assertSame('synthetic-n1-response-secret', $error->getDescription());
            }
            if ($json === '[]' || $json === 'null' || str_ends_with($json, ':')) {
                self::assertInstanceOf(\JsonException::class, $error->getPrevious());
            }
        }
        $logs->assertSafe(['synthetic-n1-response-secret', 'synthetic-n1-payload-secret']);
    }

    public static function legacyResponses(): iterable
    {
        foreach (['control', 'publish'] as $route) {
            yield $route . '-missing-payload' => [$route, false];
            yield $route . '-invalid-payload' => [$route, true];
        }
    }

    #[DataProvider('legacyResponses')]
    public function testLegacyResponseWithoutValidPayloadIsControlled(string $route, bool $invalid): void
    {
        $message = $invalid ? new class implements MsgMessageInterface {
            public function getSubject(): string
            {
                return 'reply';
            }
            public function getReplyTo(): string|null
            {
                return null;
            }
            public function getPayloadSize(): int
            {
                return 0;
            }
            public function getSid(): string
            {
                return '1';
            }
            public function getPayload(): array
            {
                return ['synthetic-n1-response-secret'];
            }
        } : self::createStub(MsgMessageInterface::class);
        $client  = self::createMock(ClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn($message);
        $logs = new CapturedProtocolLogs();
        try {
            if ($route === 'control') {
                (new JetStreamControlPlaneTransport($client, logger: $logs))->request('INFO', []);
            } else {
                (new JetStreamPublisher($client, $logs))->publish('orders', 'payload');
            }
            self::fail('Expected controlled invalid response error');
        } catch (JetStreamApiException $error) {
            self::assertSame($route === 'control' ? 0 : 500, $error->getCode());
        }
        $logs->assertSafe(['synthetic-n1-response-secret']);
    }

    public function testLegacyClientCannotReceiveAReplayableUpdate(): void
    {
        $client = self::createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('subscribe');
        $transport = new JetStreamControlPlaneTransport($client);
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('SingleAttemptRequestClientInterface');
        $transport->request('STREAM.UPDATE.S', ['name' => 'S']);
    }

    public function testSuccessfulResponseDataIsNotAutomaticallyLogged(): void
    {
        $client = self::createMock(ClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn(new MsgMessage('reply', '1', '{"stream":"synthetic-n1-response-secret","seq":1}'));
        $logs = new CapturedProtocolLogs();
        $ack  = (new JetStreamPublisher($client, $logs))->publish('orders', 'payload');
        self::assertSame('synthetic-n1-response-secret', $ack->getStream());
        $logs->assertSafe(['synthetic-n1-response-secret']);
    }

    public function testConvertedPublishFailurePreservesCauseAndHidesItFromLogs(): void
    {
        $cause  = new RuntimeException('synthetic-n1-exception-secret');
        $client = self::createMock(ClientInterface::class);
        $client->expects(self::once())->method('request')->willThrowException($cause);
        $logs = new CapturedProtocolLogs();
        try {
            (new JetStreamPublisher($client, $logs))->publish('orders', 'payload');
            self::fail('Expected failure');
        } catch (JetStreamApiException $error) {
            self::assertSame($cause, $error->getPrevious());
            self::assertSame('synthetic-n1-exception-secret', $error->getDescription());
            self::assertSame(500, $error->getCode());
        }
        $logs->assertSafe(['synthetic-n1-exception-secret']);
    }
}
