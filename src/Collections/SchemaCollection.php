<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use SQLCraft\DTO\SchemaMeta;

/** @extends AbstractImmutableCollection<SchemaMeta> */
final class SchemaCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, SchemaMeta> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}
