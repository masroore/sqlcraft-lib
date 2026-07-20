<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

abstract class ConstraintViolationException extends QueryException
{
    public function __construct(
        string $message,
        string $sql = '',
        public readonly string $constraintName = '',
        public readonly string $table = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $sql, $code, $previous);
    }
}
