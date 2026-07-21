<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

abstract class SQLCraftException extends \RuntimeException
{
    public function __toString(): string
    {
        return sprintf('%s: %s in %s:%d\nStack trace:\n%s', static::class, $this->getMessage(), $this->getFile(), $this->getLine(), $this->getTraceAsString());
    }
}
