<?php

declare(strict_types=1);

namespace Outbox\Exception;

/** No answer from the API after every attempt. The request may or may not have reached it. */
final class ConnectionException extends OutboxException
{
    /** @param 'timeout'|'connection_error' $errorCode */
    public function __construct(string $message, private readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @return 'timeout'|'connection_error' */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
