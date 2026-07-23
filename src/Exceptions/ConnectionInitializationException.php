<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

final class ConnectionInitializationException extends ConnectionException
{
    public function __construct(
        string $message,
        string $host = '',
        string $driver = '',
        ?\Throwable $previous = null,
        public readonly ?\Throwable $notificationError = null,
    ) {
        parent::__construct($message, $host, $driver, previous: $previous);
    }
}
