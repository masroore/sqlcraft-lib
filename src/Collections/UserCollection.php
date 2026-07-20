<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\UserMeta;

/** @extends AbstractImmutableCollection<UserMeta> */
final class UserCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, UserMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
