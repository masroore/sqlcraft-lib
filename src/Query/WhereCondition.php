<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use InvalidArgumentException;
use SQLCraft\ValueObjects\Identifier;

final readonly class WhereCondition
{
    public function __construct(
        public Identifier $column,
        string $operator,
        public mixed $value,
    ) {
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, [
            '=', '!=', '<>', '<', '<=', '>', '>=',
            'LIKE', 'NOT LIKE', 'IN', 'NOT IN',
            'IS NULL', 'IS NOT NULL', 'BETWEEN', 'NOT BETWEEN',
            'REGEXP', 'NOT REGEXP',
        ], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported WHERE operator: %s', $operator));
        }
        $this->operator = $operator;
    }

    public string $operator;
}
