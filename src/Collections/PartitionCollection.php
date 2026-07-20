<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\PartitionInfo;

/** @extends AbstractImmutableCollection<PartitionInfo> */
final class PartitionCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, PartitionInfo> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
