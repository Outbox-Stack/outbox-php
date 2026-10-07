<?php

declare(strict_types=1);

namespace Outbox;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use Outbox\Exception\ApiException;
use Outbox\Exception\ConnectionException;
use Outbox\Exception\OutboxException;
use Psr\Http\Message\ResponseInterface;

/**
 * Server-side Outbox Stack client.
 *
 * Requests that cannot cause a duplicate send are retried with backoff: every send() carries an
 * idempotency key (generated when you don't pass one), so a retry returns the original message.
 */
final class Client
{
    public const VERSION = '0.2.0';
    private const DEFAULT_BASE_URL = 'https://outboxstack.app';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly ClientInterface $http;
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param string|null $apiKey Defaults to the OUTBOX_API_KEY environment variable.
     * @param string|null $baseUrl Defaults to OUTBOX_BASE_URL, then https://outboxstack.app.
     * @param float $timeout Seconds per attempt.
     * @param int $maxRetries Retries after timeouts, connection errors, 408, 429 and 5xx.
     * @param (\Closure(int): void)|null $sleep Receives milliseconds; injectable for tests.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 10.0,
        private readonly int $maxRetries = 2,
        ?ClientInterface $http = null,
        ?\Closure $sleep = null,
    ) {
        $apiKey ??= self::env('OUTBOX_API_KEY');
        if ($apiKey === null || $apiKey === '') {
            throw new OutboxException('An API key is required: pass apiKey or set OUTBOX_API_KEY');
        }
        $baseUrl ??= self::env('OUTBOX_BASE_URL') ?? self::DEFAULT_BASE_URL;
        $url = parse_url($baseUrl);
        if ($url === false || !isset($url['scheme'], $url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || (($url['path'] ?? '/') !== '/')) {
            throw new OutboxException('baseUrl must be the platform origin, e.g. https://outboxstack.app');
        }
        $local = in_array($url['host'], ['localhost', '127.0.0.1', '[::1]'], true);
        if ($url['scheme'] !== 'https' && !($url['scheme'] === 'http' && $local)) {
            throw new OutboxException('Use HTTPS outside localhost');
        }
        if ($timeout <= 0) {
            throw new OutboxException('timeout must be positive');
        }
        if ($maxRetries < 0 || $maxRetries > 10) {
            throw new OutboxException('maxRetries must be from 0 to 10');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        $this->http = $http ?? new Guzzle(['timeout' => $timeout, 'connect_timeout' => min($timeout, 5.0)]);
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Queues one message. Accepted is not delivered: track it with getMessage() or webhooks.
     *
     * Fields: from, to (string or list), cc, bcc, fromName, toName, replyTo, subject, html, text,
     * template + data, headers, metadata, attachments. Attachment `content` is raw bytes (the
     * client base64-encodes it); `path` reads a file instead.
     *
     * @param array<string, mixed> $message
     * @return array{id: string, status: string, duplicate?: bool, recipients?: list<array{email: string, id: string, status: string, duplicate: bool}>}
     */
    public function send(array $message, ?string $idempotencyKey = null): array
    {
        self::checkIdempotencyKey($idempotencyKey);
        self::checkContent($message);
        /** @var array{id: string, status: string} */
        return $this->request('POST', '/v1/messages', self::encodeMessage($message), $idempotencyKey ?? self::uuid(), true);
    }

    /**
     * Sends 1-100 messages; items succeed or fail independently. Retried only when every item has an idempotencyKey.
     *
     * @param list<array<string, mixed>> $messages
     * @return array{accepted: int, failed: int, results: list<array<string, mixed>>}
     */
    public function sendBatch(array $messages): array
    {
        if ($messages === [] || count($messages) > 100 || !array_is_list($messages)) {
            throw new OutboxException('sendBatch takes a list of 1 to 100 messages');
        }
        $retryable = true;
        foreach ($messages as $i => $message) {
            self::checkIdempotencyKey($message['idempotencyKey'] ?? null);
            self::checkContent($message, "messages[$i]: ");
            $retryable = $retryable && isset($message['idempotencyKey']);
        }
        /** @var array{accepted: int, failed: int, results: list<array<string, mixed>>} */
        return $this->request('POST', '/v1/messages/batch', ['messages' => array_map(self::encodeMessage(...), $messages)], null, $retryable);
    }

    /** @return array<string, mixed> */
    public function getMessage(string $id): array
    {
        if (preg_match(self::UUID, $id) !== 1) {
            throw new OutboxException('A message UUID is required');
        }
        return $this->request('GET', '/v1/messages/' . $id, null, null, true);
    }

    /**
     * The newest messages, most recent first. For older ones use listMessagesPage() or iterateMessages().
     *
     * @param string|null $to Only recipients whose address contains this text (case-insensitive).
     * @param int|null $limit 1-200, default 50.
     * @return list<array<string, mixed>>
     */
    public function listMessages(?string $status = null, ?int $limit = null, ?string $to = null, ?string $after = null): array
    {
        return $this->listMessagesPage($status, $limit, $to, $after)['messages'];
    }

    /**
     * One page, most recent first. Pass `next` back as $after until it is null.
     *
     * @return array{messages: list<array<string, mixed>>, next: string|null}
     */
    public function listMessagesPage(?string $status = null, ?int $limit = null, ?string $to = null, ?string $after = null): array
    {
        $page = $this->request('GET', '/v1/messages' . self::query(['status' => $status, 'to' => $to, 'limit' => $limit, 'after' => $after]), null, null, true);
        /** @var array{messages: list<array<string, mixed>>, next: string|null} */
        return ['messages' => $page['messages'] ?? [], 'next' => is_string($page['next'] ?? null) ? $page['next'] : null];
    }

    /**
     * Every matching message, most recent first, fetched a page at a time.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateMessages(?string $status = null, ?int $limit = null, ?string $to = null, ?string $after = null): \Generator
    {
        do {
            $page = $this->listMessagesPage($status, $limit, $to, $after);
            yield from $page['messages'];
            $after = $page['next'];
        } while ($after !== null);
    }

    /**
     * Stops a message that hasn't started sending. Each recipient of a multi-recipient send has its own id.
     * Throws ApiException with errorCode() "not_cancellable" once it is sending or finished. Safe to retry.
     *
     * @return array{id: string, status: string}
     */
    public function cancelMessage(string $id): array
    {
        if (preg_match(self::UUID, $id) !== 1) {
            throw new OutboxException('A message UUID is required');
        }
        /** @var array{id: string, status: string} */
        return $this->request('POST', '/v1/messages/' . $id . '/cancel', null, null, true);
    }

    /**
     * The newest suppressed addresses. Suppression calls need a full-access API key.
     *
     * @param int|null $limit 1-1000, default 1000.
     * @return list<array{email: string, reason: string, created_at: string}>
     */
    public function listSuppressions(?int $limit = null, ?string $after = null): array
    {
        return $this->listSuppressionsPage($limit, $after)['suppressions'];
    }

    /**
     * One page, newest first. Pass `next` back as $after until it is null.
     *
     * @return array{suppressions: list<array{email: string, reason: string, created_at: string}>, next: string|null}
     */
    public function listSuppressionsPage(?int $limit = null, ?string $after = null): array
    {
        $page = $this->request('GET', '/v1/suppressions' . self::query(['limit' => $limit, 'after' => $after]), null, null, true);
        /** @var array{suppressions: list<array{email: string, reason: string, created_at: string}>, next: string|null} */
        return ['suppressions' => $page['suppressions'] ?? [], 'next' => is_string($page['next'] ?? null) ? $page['next'] : null];
    }

    /**
     * Every suppressed address, newest first, fetched a page at a time.
     *
     * @return \Generator<int, array{email: string, reason: string, created_at: string}>
     */
    public function iterateSuppressions(?int $limit = null, ?string $after = null): \Generator
    {
        do {
            $page = $this->listSuppressionsPage($limit, $after);
            yield from $page['suppressions'];
            $after = $page['next'];
        } while ($after !== null);
    }

    /**
     * Stops all mail to an address. Adding one that is already suppressed succeeds and keeps its reason.
     *
     * @return array{email: string}
     */
    public function addSuppression(string $email): array
    {
        self::checkEmail($email);
        /** @var array{email: string} */
        return $this->request('POST', '/v1/suppressions', ['email' => $email], null, true);
    }

    /**
     * Removes a "manual" suppression. Bounces, complaints and unsubscribes can't be removed (errorCode
     * "conflict"); an address that isn't suppressed gives "not_found". Not retried automatically: a retry
     * after a lost response would report not_found for an address that was in fact removed.
     *
     * @return array{removed: string}
     */
    public function removeSuppression(string $email): array
    {
        self::checkEmail($email);
        /** @var array{removed: string} */
        return $this->request('DELETE', '/v1/suppressions/' . rawurlencode($email), null, null, false);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, ?string $idempotencyKey, bool $retryable): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json', 'User-Agent' => 'outbox-php/' . self::VERSION];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $options = ['headers' => $headers, 'http_errors' => false, 'allow_redirects' => false];
        if ($body !== null) {
            $options['body'] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $options['headers']['Content-Type'] = 'application/json';
        }
        for ($attempt = 0; ; $attempt++) {
            $canRetry = $retryable && $attempt < $this->maxRetries;
            try {
                $response = $this->http->request($method, $this->baseUrl . $path, $options);
            } catch (TransferException $e) {
                if (!$canRetry) {
                    $timedOut = $e instanceof ConnectException && stripos($e->getMessage(), 'timed out') !== false;
                    throw (new ConnectionException($timedOut ? 'Outbox did not respond in time' : 'Could not reach the Outbox API: ' . $e->getMessage(), $timedOut ? 'timeout' : 'connection_error', $e))->withIdempotencyKey($idempotencyKey);
                }
                ($this->sleep)(self::retryDelay($attempt, null));
                continue;
            } catch (GuzzleException $e) {
                throw (new OutboxException('Outbox API request failed: ' . $e->getMessage(), 0, $e))->withIdempotencyKey($idempotencyKey);
            }
            $status = $response->getStatusCode();
            $data = self::decode($response);
            $ok = $status >= 200 && $status < 300;
            if ($ok && $data !== null) {
                return $data;
            }
            $retryAfter = self::parseRetryAfter($response->getHeaderLine('Retry-After'));
            // A 2xx with an unreadable body may have been accepted: retry with the same key to find out.
            if ($canRetry && ($ok || $status === 408 || $status === 429 || $status >= 500)) {
                ($this->sleep)(self::retryDelay($attempt, $retryAfter));
                continue;
            }
            $requestId = $response->getHeaderLine('X-Request-Id') ?: null;
            if ($ok) {
                throw (new ApiException($status, 'Outbox API returned HTTP ' . $status . ' with an unreadable body', null, $requestId, null, $retryAfter, 'invalid_response'))->withIdempotencyKey($idempotencyKey);
            }
            $message = is_string($data['error'] ?? null) ? $data['error'] : 'Outbox API returned HTTP ' . $status;
            throw (new ApiException($status, $message, $data, $requestId, null, $retryAfter))->withIdempotencyKey($idempotencyKey);
        }
    }

    /** @return array<string, mixed>|null */
    private static function decode(ResponseInterface $response): ?array
    {
        $data = json_decode((string) $response->getBody(), true);
        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }

    /** Retry-After when given (capped at 30s), else exponential backoff with full jitter. */
    private static function retryDelay(int $attempt, ?int $retryAfterMs): int
    {
        if ($retryAfterMs !== null) {
            return min($retryAfterMs, 30_000);
        }
        return random_int(0, (int) min(500 * 2 ** $attempt, 8_000));
    }

    /** Retry-After as delay-seconds or an HTTP date, in milliseconds from now. */
    private static function parseRetryAfter(string $value, ?int $now = null): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value * 1000;
        }
        $at = strtotime($value);
        return $at === false ? null : max(($at - ($now ?? time())) * 1000, 0);
    }

    /** @param array<string, string|int|null> $params */
    private static function query(array $params): string
    {
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        return $params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Content is either subject with html and/or text, or a template. The API rejects the first two
     * mistakes and silently ignores inline content sent alongside a template, so all three stop here.
     *
     * @param array<string, mixed> $message
     */
    private static function checkContent(array $message, string $prefix = ''): void
    {
        $inline = array_filter(['subject', 'html', 'text'], static fn (string $k) => isset($message[$k]) && $message[$k] !== '');
        if (isset($message['template'])) {
            if ($inline !== []) {
                throw new OutboxException($prefix . 'Use either template or subject/html/text, not both: the template would replace ' . implode(', ', $inline));
            }
            return;
        }
        if (!in_array('subject', $inline, true)) {
            throw new OutboxException($prefix . 'A message needs a subject with html and/or text, or a template');
        }
        if (!in_array('html', $inline, true) && !in_array('text', $inline, true)) {
            throw new OutboxException($prefix . 'A message with a subject needs html and/or text');
        }
    }

    private static function checkEmail(mixed $email): void
    {
        if (!is_string($email) || !str_contains($email, '@') || strlen($email) > 254) {
            throw new OutboxException('A valid email address is required');
        }
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private static function encodeMessage(array $message): array
    {
        if (!isset($message['attachments'])) {
            return $message;
        }
        if (!is_array($message['attachments'])) {
            throw new OutboxException('attachments must be a list');
        }
        $message['attachments'] = array_map(static function (mixed $file): array {
            if (!is_array($file)) {
                throw new OutboxException('Each attachment must be an array');
            }
            if (isset($file['path'])) {
                $path = $file['path'];
                if (!is_string($path)) {
                    throw new OutboxException('Attachment path must be a string');
                }
                $content = @file_get_contents($path);
                if ($content === false) {
                    throw new OutboxException('Cannot read attachment ' . $path);
                }
                $file['filename'] ??= basename($path);
                $file['content'] = $content;
                unset($file['path']);
            }
            if (!is_string($file['content'] ?? null)) {
                throw new OutboxException('Each attachment needs content (raw bytes) or path');
            }
            $file['content'] = base64_encode($file['content']);
            return $file;
        }, array_values($message['attachments']));
        return $message;
    }

    private static function checkIdempotencyKey(mixed $key): void
    {
        if ($key !== null && (!is_string($key) || trim($key) === '' || strlen($key) > 200)) {
            throw new OutboxException('idempotencyKey must contain 1-200 characters');
        }
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? null : $value;
    }
}
