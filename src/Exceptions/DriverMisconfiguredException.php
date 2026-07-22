<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

use SQLCraft\Enums\DatabaseDriver;

final class DriverMisconfiguredException extends DriverException
{
    public function __construct(
        string $message,
        public readonly string|DatabaseDriver $driver = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
