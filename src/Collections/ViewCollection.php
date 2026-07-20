<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\ViewMeta;

/** @extends AbstractImmutableCollection<ViewMeta> */
final class ViewCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, ViewMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
