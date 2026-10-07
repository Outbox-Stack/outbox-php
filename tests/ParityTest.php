<?php

declare(strict_types=1);

namespace Outbox\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Outbox\Client;
use Outbox\Exception\ApiException;
use Outbox\Exception\ConnectionException;
use Outbox\Exception\OutboxException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Features added in 0.2.0 to match the Node SDK 0.4.0. */
final class ParityTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];
    /** @var list<int> */
    private array $sleeps = [];

    /** @param list<Response|\Throwable> $responses */
    private function client(array $responses, int $maxRetries = 2): Client
    {
        $this->history = [];
        $this->sleeps = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new Client(apiKey: 'k', baseUrl: 'https://mail.example.com', maxRetries: $maxRetries, http: new Guzzle(['handler' => $stack]), sleep: function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    private static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function url(int $i): string
    {
        return (string) $this->history[$i]['request']->getUri();
    }

    public function testMessagePagesCarryFiltersAndCursor(): void
    {
        $client = $this->client([self::json(['messages' => [['id' => 'a']], 'next' => 'cur1'])]);
        $page = $client->listMessagesPage(status: 'bounced', limit: 2, to: 'ada@', after: 'cur0');
        self::assertSame(['messages' => [['id' => 'a']], 'next' => 'cur1'], $page);
        self::assertSame('https://mail.example.com/v1/messages?status=bounced&to=ada%40&limit=2&after=cur0', $this->url(0));
    }

    public function testIterateMessagesFollowsEveryPage(): void
    {
        $client = $this->client([
            self::json(['messages' => [['id' => 'a'], ['id' => 'b']], 'next' => 'c1']),
            self::json(['messages' => [['id' => 'c']], 'next' => null]),
        ]);
        $ids = array_map(static fn (array $m) => $m['id'], iterator_to_array($client->iterateMessages(to: 'x'), false));
        self::assertSame(['a', 'b', 'c'], $ids);
        self::assertStringEndsWith('?to=x&after=c1', $this->url(1));
    }

    public function testCancelIsRetriedAndReportsNotCancellable(): void
    {
        $client = $this->client([self::json(['error' => 'busy'], 503, ['Retry-After' => '0']), self::json(['id' => self::ID, 'status' => 'cancelled'])]);
        self::assertSame('cancelled', $client->cancelMessage(self::ID)['status']);
        self::assertSame('https://mail.example.com/v1/messages/' . self::ID . '/cancel', $this->url(1));
        self::assertSame('POST', $this->history[1]['request']->getMethod());

        $client = $this->client([self::json(['error' => 'already sending', 'code' => 'not_cancellable'], 409)]);
        try {
            $client->cancelMessage(self::ID);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('not_cancellable', $e->errorCode());
        }
        $this->expectException(OutboxException::class);
        $client->cancelMessage('not-a-uuid');
    }

    public function testSuppressions(): void
    {
        $client = $this->client([
            self::json(['suppressions' => [['email' => 'a@x.com', 'reason' => 'bounce', 'created_at' => 't']], 'next' => 'n1']),
            self::json(['suppressions' => [['email' => 'b@x.com', 'reason' => 'manual', 'created_at' => 't']], 'next' => null]),
            self::json(['email' => 'c@x.com'], 201),
            self::json(['removed' => 'c+tag@x.com']),
        ]);
        $emails = array_map(static fn (array $s) => $s['email'], iterator_to_array($client->iterateSuppressions(limit: 1), false));
        self::assertSame(['a@x.com', 'b@x.com'], $emails);
        self::assertSame('https://mail.example.com/v1/suppressions?limit=1&after=n1', $this->url(1));
        $client->addSuppression('c@x.com');
        self::assertSame(['email' => 'c@x.com'], json_decode((string) $this->history[2]['request']->getBody(), true));
        $client->removeSuppression('c+tag@x.com');
        self::assertSame('DELETE', $this->history[3]['request']->getMethod());
        self::assertSame('https://mail.example.com/v1/suppressions/c%2Btag%40x.com', $this->url(3));
    }

    public function testRemoveSuppressionIsNotRetried(): void
    {
        $client = $this->client([self::json(['error' => 'down'], 503, ['Retry-After' => '0']), self::json(['removed' => 'a@x.com'])]);
        try {
            $client->removeSuppression('a@x.com');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(503, $e->status());
        }
        self::assertCount(1, $this->history);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badContent(): iterable
    {
        $base = ['from' => 'a@x.com', 'to' => 'b@x.com'];
        yield 'no content' => [$base];
        yield 'subject without body' => [$base + ['subject' => 'Hi']];
        yield 'template with inline content' => [$base + ['template' => 'welcome', 'subject' => 'Hi']];
        yield 'body without subject' => [$base + ['html' => '<p>Hi</p>']];
    }

    /** @param array<string, mixed> $message */
    #[\PHPUnit\Framework\Attributes\DataProvider('badContent')]
    public function testInvalidContentIsRejectedBeforeSending(array $message): void
    {
        $client = $this->client([]);
        try {
            $client->send($message);
            self::fail('expected OutboxException');
        } catch (OutboxException $e) {
            self::assertNotInstanceOf(ApiException::class, $e);
        }
        self::assertCount(0, $this->history);
    }

    public function testTemplateMessagesAndBatchesAreChecked(): void
    {
        $client = $this->client([self::json(['id' => self::ID, 'status' => 'queued'], 202)]);
        $client->send(['from' => 'a@x.com', 'to' => 'b@x.com', 'template' => 'welcome', 'data' => ['name' => 'Ada']]);
        self::assertCount(1, $this->history);
        $this->expectExceptionMessage('messages[1]: ');
        $client->sendBatch([['from' => 'a@x.com', 'to' => 'b@x.com', 'subject' => 's', 'text' => 't'], ['from' => 'a@x.com', 'to' => 'b@x.com']]);
    }

    public function testUnreadableSuccessIsRetriedThenReported(): void
    {
        $client = $this->client([new Response(202, [], ''), new Response(202, [], 'not json'), new Response(202, [], '')]);
        try {
            $client->send(['from' => 'a@x.com', 'to' => 'b@x.com', 'subject' => 's', 'text' => 't'], idempotencyKey: 'order-1');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('invalid_response', $e->errorCode());
            self::assertSame('order-1', $e->idempotencyKey());
        }
        self::assertCount(3, $this->history);
    }

    public function testConnectionFailureAfterRetriesKeepsTheKey(): void
    {
        $down = static fn () => new ConnectException('Connection refused', new Request('POST', '/'));
        $client = $this->client([$down(), $down(), $down()]);
        try {
            $client->send(['from' => 'a@x.com', 'to' => 'b@x.com', 'subject' => 's', 'text' => 't'], idempotencyKey: 'order-2');
            self::fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('connection_error', $e->errorCode());
            self::assertSame('order-2', $e->idempotencyKey());
        }
    }

    public function testRetryAfterHttpDateIsHonoured(): void
    {
        $date = gmdate('D, d M Y H:i:s', time() + 3) . ' GMT';
        $client = $this->client([self::json(['error' => 'slow down'], 429, ['Retry-After' => $date]), self::json(['messages' => [], 'next' => null])]);
        $client->listMessages();
        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(1_000, $this->sleeps[0]);
        self::assertLessThanOrEqual(3_000, $this->sleeps[0]);
    }
}
