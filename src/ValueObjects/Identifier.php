<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;
use SQLCraft\Support\StringUtil;

final readonly class Identifier
{
    public function __construct(public string $name)
    {
        if ($name === '' || StringUtil::containsNullByte($name)) {
            throw new InvalidArgumentException(sprintf("Invalid identifier: '%s'", $name));
        }
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
