<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;
use SQLCraft\Support\StringUtil;

final readonly class Charset
{
    public function __construct(public string $name)
    {
        if ($name === '' || StringUtil::containsNullByte($name)) {
            throw new InvalidArgumentException("Invalid charset: '{$name}'");
        }
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->name, $other->name) === 0;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
