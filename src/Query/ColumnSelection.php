<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\ValueObjects\Identifier;

final readonly class ColumnSelection
{
    public function __construct(
        public Identifier $column,
        public ?string $aggregateFunction = null,
        public ?Identifier $alias = null,
    ) {
        if ($aggregateFunction !== null && preg_match('/^[A-Za-z]\w*$/', $aggregateFunction) !== 1) {
            throw new \InvalidArgumentException('Aggregate function must be a valid SQL function name.');
        }
    }
}
