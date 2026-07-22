<?php

declare(strict_types=1);

namespace SQLCraft\Capabilities;

final readonly class ExtendedCapability
{
    public function __construct(public string $name) {}

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
