<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\ValueObjects\DataType;

/** @extends AbstractImmutableCollection<DataType> */
final class TypeCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, DataType> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
