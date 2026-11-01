<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Dorpmaster\Nats\Domain\JetStream\Exception\FastBatchException;
use Dorpmaster\Nats\Domain\JetStream\Internal\FastBatchResponse;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\FakeFastBatchClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FastBatchResponseTest extends TestCase
{
    public static function invalidControls(): iterable
    {
        foreach (
            [
            '', '[]', '{"type":42}', '{"type":9223372036854775808}', '{"type":"unknown"}',
            '{"type":"ack","msgs":1}', '{"type":"ack","seq":"0","msgs":1}', '{"type":"ack","seq":-1,"msgs":1}',
            '{"type":"ack","seq":1e3,"msgs":1}', '{"type":"ack","seq":1,"msgs":0}', '{"type":"ack","seq":1,"msgs":"1"}', '{"type":"ack","seq":1,"msgs":65536}',
            '{"type":"ack","seq":1,"seq":2,"msgs":1}', '{"type":"gap","last_seq":0,"seq":2}', '{"type":"gap","last_seq":2,"seq":2}',
            '{"type":"gap","last_seq":"1","seq":2}', '{"type":"err","error":{"code":400,"description":"bad"}}',
            '{"type":"err","seq":0,"error":{"code":400,"description":"bad"}}', '{"error":42}', '{"error":{"code":400,"description":9223372036854775808}}',
            '{"error":{"code":400,"description":"bad","err_code":"10071"}}', '{"error":{"code":400,"description":"bad","err_code":null}}',
            '{"error":{"code":200,"description":"bad"}}', '{"error":{"code":400,"description":""}}'
            ] as $json
        ) {
            yield [$json];
        }
    }
    #[DataProvider('invalidControls')]
    public function testMalformedControlIsNotProgress(string $json): void
    {
        $this->expectException(\Exception::class);
        FastBatchResponse::parse(new MsgMessage('ack', '1', $json));
    }
    public function testControlCountersKeepTheirJsonTypesAndUInt64Precision(): void
    {
        $response = FastBatchResponse::parse(new MsgMessage('ack', '1', '{"type":"ack","seq":18446744073709551615,"msgs":65535}'));
        self::assertSame('18446744073709551615', $response->sequence);
        self::assertSame(65535, $response->window);
        $response = FastBatchResponse::parse(new MsgMessage('ack', '1', '{"type":"err","seq":9223372036854775808,"error":{"code":400,"err_code":10071,"description":"conflict"}}'));
        self::assertSame('9223372036854775808', $response->sequence);
        self::assertSame(10071, $response->error->getErrorCode());
    }
    public static function invalidFinals(): iterable
    {
        foreach (
            [
            '{"stream":"OTHER","batch":"ID","count":1,"seq":1}', '{"stream":"S","batch":"other","count":1,"seq":1}',
            '{"stream":"S","batch":"ID","count":2,"seq":2}', '{"stream":"S","batch":"ID","count":"1","seq":1}',
            '{"stream":"S","batch":"ID","count":1,"seq":"18446744073709551615"}',
            '{"stream":"S","batch":"ID","count":1,"seq":18446744073709551616}', '{"stream":"S","batch":"ID","count":1,"seq":1e30}',
            '{"stream":9223372036854775808,"batch":"ID","count":1,"seq":1}', '{}'
            ] as $json
        ) {
            yield [$json];
        }
    }
    #[DataProvider('invalidFinals')]
    public function testMalformedFinalCannotConfirmTheWrittenFrame(string $json): void
    {
        $client          = new FakeFastBatchClient();
        $client->onFrame = static function ($message, $channel) use ($json): void {
            $tokens = explode('.', $message->getReplyTo());
            $id     = $tokens[count($tokens) - 6];
            $channel->emit(str_replace('ID', $id, $json));
        };
        $s               = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a']);
        try {
            $s->commit('orders.a', 'written');
            self::fail('Malformed final');
        } catch (FastBatchException $error) {
            self::assertFalse($error->getResult()->isComplete());
            self::assertTrue($error->getResult()->isOutcomeUnknown());
            self::assertSame('0', $error->getResult()->getLastAcknowledgedSequence());
            self::assertSame([['from' => '1','to' => '1']], $error->getResult()->getUnknownRanges());
        }
    }
}
