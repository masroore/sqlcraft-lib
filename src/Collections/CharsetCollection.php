<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\ValueObjects\Charset;

/** @extends AbstractImmutableCollection<Charset> */
final class CharsetCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, Charset> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
