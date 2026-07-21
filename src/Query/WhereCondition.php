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
        if (preg_match('/^(?:[A-Z][A-Z0-9]*(?: [A-Z0-9]+)*|[!<>=~]+)$/', $operator) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid WHERE operator: %s', $operator));
        }
        $this->operator = $operator;
    }

    public string $operator;
}
