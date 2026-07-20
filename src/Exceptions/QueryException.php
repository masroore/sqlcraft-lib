<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

abstract class QueryException extends SQLCraftException
{
    public function __construct(
        string $message,
        public readonly string $sql = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
