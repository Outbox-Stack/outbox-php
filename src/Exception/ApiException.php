<?php

declare(strict_types=1);

namespace Outbox\Exception;

/** The API answered with a non-2xx status. Branch on errorCode(); the message text may change. */
final class ApiException extends OutboxException
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly ?array $body = null,
        private readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Stable machine-readable code such as "domain_not_verified" or "quota_exceeded". */
    public function errorCode(): string
    {
        return is_string($this->body['code'] ?? null) ? $this->body['code'] : 'error';
    }

    /** Quote this to support. */
    public function requestId(): ?string
    {
        return is_string($this->body['request_id'] ?? null) ? $this->body['request_id'] : $this->requestId;
    }

    /** @return array<string, mixed>|null */
    public function body(): ?array
    {
        return $this->body;
    }
}
