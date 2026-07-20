<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\SequenceMeta;

/** @extends AbstractImmutableCollection<SequenceMeta> */
final class SequenceCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, SequenceMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
