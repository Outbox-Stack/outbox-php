<?php

declare(strict_types=1);

namespace Outbox\Exception;

class OutboxException extends \RuntimeException
{
    private ?string $idempotencyKey = null;

    /** The idempotency key the failed request carried. Retry with it and the API will not send twice. */
    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /** @internal */
    public function withIdempotencyKey(?string $key): static
    {
        $this->idempotencyKey = $key;
        return $this;
    }
}
