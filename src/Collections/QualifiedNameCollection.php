<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\ValueObjects\QualifiedName;

/** @extends AbstractImmutableCollection<QualifiedName> */
final class QualifiedNameCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, QualifiedName> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
