<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\ValueObjects\Privilege;

/** @extends AbstractImmutableCollection<Privilege> */
final class PrivilegeCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, Privilege> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
