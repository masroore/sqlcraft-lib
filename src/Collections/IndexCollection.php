<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\IndexMeta;

/** @extends AbstractImmutableCollection<IndexMeta> */
final class IndexCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, IndexMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
