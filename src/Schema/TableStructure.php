<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Collections\ForeignKeyCollection;
use SQLCraft\Collections\IndexCollection;
use SQLCraft\Collections\TriggerCollection;
use SQLCraft\DTO\TableStatus;

final readonly class TableStructure
{
    public function __construct(
        public TableStatus $status,
        public ColumnCollection $columns,
        public IndexCollection $indexes,
        public ForeignKeyCollection $foreignKeys,
        public TriggerCollection $triggers,
    ) {}
}
