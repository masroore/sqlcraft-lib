<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\ColumnMeta;

/** @extends AbstractImmutableCollection<ColumnMeta> */
final class ColumnCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, ColumnMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
