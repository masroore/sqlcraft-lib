<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\RoutineMeta;

/** @extends AbstractImmutableCollection<RoutineMeta> */
final class RoutineCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, RoutineMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
