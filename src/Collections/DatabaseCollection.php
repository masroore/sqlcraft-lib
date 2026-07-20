<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\DatabaseMeta;

/** @extends AbstractImmutableCollection<DatabaseMeta> */
final class DatabaseCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, DatabaseMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
