<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\TableStatus;

/** @extends AbstractImmutableCollection<TableStatus> */
final class TableCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, TableStatus> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
