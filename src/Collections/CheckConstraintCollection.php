<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\CheckConstraintMeta;

/** @extends AbstractImmutableCollection<CheckConstraintMeta> */
final class CheckConstraintCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, CheckConstraintMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
