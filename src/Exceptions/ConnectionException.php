<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

abstract class ConnectionException extends SQLCraftException
{
    public function __construct(
        string $message,
        public readonly string $host = '',
        public readonly string $driver = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
