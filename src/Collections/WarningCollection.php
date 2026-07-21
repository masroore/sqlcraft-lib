<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\QueryWarning;

/** @extends AbstractImmutableCollection<QueryWarning> */
final class WarningCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, QueryWarning> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
