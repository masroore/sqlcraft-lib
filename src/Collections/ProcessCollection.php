<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\ProcessMeta;

/** @extends AbstractImmutableCollection<ProcessMeta> */
final class ProcessCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, ProcessMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
