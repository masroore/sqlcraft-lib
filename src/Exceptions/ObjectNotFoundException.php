<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

final class ObjectNotFoundException extends MetadataException
{
    public function __construct(
        string $message,
        public readonly string $qualifiedName = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
