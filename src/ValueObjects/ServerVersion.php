<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;

final readonly class ServerVersion
{
    public string $value;
    public int $major;
    public int $minor;
    public int $patch;

    public function __construct(string $value)
    {
        $value = trim($value);

        if (preg_match('/^[^0-9]*(\d+)\.(\d+)(?:\.(\d+))?(?:[-+][0-9A-Za-z.-]+)?(?:\s.*)?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid server version: '{$value}'");
        }

        $this->value = $value;
        $this->major = (int) $matches[1];
        $this->minor = (int) $matches[2];
        $this->patch = isset($matches[3]) ? (int) $matches[3] : 0;
    }

    public function isAtLeast(int $major, int $minor = 0, int $patch = 0): bool
    {
        return $this->compare($major, $minor, $patch) >= 0;
    }

    public function compare(int $major, int $minor = 0, int $patch = 0): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$major, $minor, $patch];
    }

    public function equals(self $other): bool
    {
        return $this->major === $other->major
            && $this->minor === $other->minor
            && $this->patch === $other->patch;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
