<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

final class DeadlockException extends QueryException
{
    public readonly bool $retryable;

    public function __construct(
        string $message,
        string $sql = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->retryable = true;
        parent::__construct($message, $sql, $code, $previous);
    }
}
