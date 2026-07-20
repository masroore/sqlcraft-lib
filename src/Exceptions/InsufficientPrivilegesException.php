<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

final class InsufficientPrivilegesException extends SecurityException
{
    public function __construct(
        string $message,
        public readonly string $privilege = '',
        public readonly string $object = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
