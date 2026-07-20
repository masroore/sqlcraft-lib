<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\TriggerMeta;

/** @extends AbstractImmutableCollection<TriggerMeta> */
final class TriggerCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, TriggerMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
