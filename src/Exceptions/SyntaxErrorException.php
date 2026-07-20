<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

final class SyntaxErrorException extends QueryException
{
    public function __construct(
        string $sql,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $sql, $code, $previous);
    }
}
