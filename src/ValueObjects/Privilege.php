<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;
use SQLCraft\Support\StringUtil;

final readonly class Privilege
{
    public string $name;

    public function __construct(string $name)
    {
        $name = strtoupper(trim($name));

        if ($name === '' || StringUtil::containsNullByte($name)) {
            throw new InvalidArgumentException("Invalid privilege: '{$name}'");
        }

        $this->name = $name;
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
