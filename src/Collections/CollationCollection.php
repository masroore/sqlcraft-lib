<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\ValueObjects\Collation;

/** @extends AbstractImmutableCollection<Collation> */
final class CollationCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, Collation> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
