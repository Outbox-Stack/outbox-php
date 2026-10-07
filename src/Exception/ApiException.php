<?php

declare(strict_types=1);

namespace Outbox\Exception;

/**
 * The API answered with a non-2xx status, or (errorCode "invalid_response") with a 2xx whose body
 * could not be read. Branch on errorCode(); the message text may change.
 */
final class ApiException extends OutboxException
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly ?array $body = null,
        private readonly ?string $requestId = null,
        ?\Throwable $previous = null,
        private readonly ?int $retryAfterMs = null,
        private readonly ?string $fallbackCode = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Stable machine-readable code such as "domain_not_verified" or "not_cancellable". */
    public function errorCode(): string
    {
        return is_string($this->body['code'] ?? null) ? $this->body['code'] : ($this->fallbackCode ?? 'error');
    }

    /** Quote this to support. */
    public function requestId(): ?string
    {
        return is_string($this->body['request_id'] ?? null) ? $this->body['request_id'] : $this->requestId;
    }

    /** The server's Retry-After hint in milliseconds, when it sent one. */
    public function retryAfterMs(): ?int
    {
        return $this->retryAfterMs;
    }

    /** @return array<string, mixed>|null */
    public function body(): ?array
    {
        return $this->body;
    }
}
