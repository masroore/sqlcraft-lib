<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\ForeignKeyMeta;

/** @extends AbstractImmutableCollection<ForeignKeyMeta> */
final class ForeignKeyCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, ForeignKeyMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
