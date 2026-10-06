<?php

declare(strict_types=1);

namespace Outbox;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use Outbox\Exception\ApiException;
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
    public const VERSION = '0.1.0';
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
        foreach ($messages as $message) {
            self::checkIdempotencyKey($message['idempotencyKey'] ?? null);
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
     * Most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function listMessages(?string $status = null, ?int $limit = null): array
    {
        $query = http_build_query(array_filter(['status' => $status, 'limit' => $limit], static fn ($v) => $v !== null));
        $result = $this->request('GET', '/v1/messages' . ($query === '' ? '' : '?' . $query), null, null, true);
        /** @var list<array<string, mixed>> */
        return $result['messages'] ?? [];
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
                    throw new OutboxException('Could not reach the Outbox API: ' . $e->getMessage(), 0, $e);
                }
                ($this->sleep)(self::retryDelay($attempt, null));
                continue;
            } catch (GuzzleException $e) {
                throw new OutboxException('Outbox API request failed: ' . $e->getMessage(), 0, $e);
            }
            $status = $response->getStatusCode();
            $data = self::decode($response);
            if ($status >= 200 && $status < 300) {
                if ($data === null) {
                    throw new ApiException($status, 'Outbox API returned invalid JSON', null, $response->getHeaderLine('X-Request-Id') ?: null);
                }
                return $data;
            }
            if ($canRetry && ($status === 408 || $status === 429 || $status >= 500)) {
                ($this->sleep)(self::retryDelay($attempt, $response->getHeaderLine('Retry-After')));
                continue;
            }
            $message = is_string($data['error'] ?? null) ? $data['error'] : 'Outbox API returned HTTP ' . $status;
            throw new ApiException($status, $message, $data, $response->getHeaderLine('X-Request-Id') ?: null);
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
    private static function retryDelay(int $attempt, ?string $retryAfter): int
    {
        if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter) && (float) $retryAfter >= 0) {
            return (int) min((float) $retryAfter * 1000, 30_000);
        }
        return random_int(0, (int) min(500 * 2 ** $attempt, 8_000));
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
