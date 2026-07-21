<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use InvalidArgumentException;
use SQLCraft\Contracts\Platform\PlatformInterface;

final readonly class WhereConditionRenderer
{
    public function __construct(private PlatformInterface $platform)
    {
    }

    /** @return array{0: string, 1: list<mixed>} */
    public function render(WhereCondition $condition): array
    {
        if (!in_array($condition->operator, $this->platform->getOperators(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Operator %s is not supported by %s.',
                $condition->operator,
                $this->platform->getName(),
            ));
        }

        $column = $this->platform->quoteIdentifier($condition->column);
        if (in_array($condition->operator, ['IS NULL', 'IS NOT NULL'], true)) {
            return [$column . ' ' . $condition->operator, []];
        }
        if (in_array($condition->operator, ['IN', 'NOT IN'], true)) {
            if (!is_array($condition->value) || $condition->value === []) {
                throw new InvalidArgumentException('IN conditions require a non-empty list of values.');
            }

            /** @var list<mixed> $values */
            $values = array_values($condition->value);

            return [
                $column . ' ' . $condition->operator . ' (' . implode(', ', array_fill(0, count($values), '?')) . ')',
                $values,
            ];
        }
        if (in_array($condition->operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
            if (!is_array($condition->value) || count($condition->value) !== 2) {
                throw new InvalidArgumentException('BETWEEN conditions require exactly two values.');
            }

            /** @var list<mixed> $values */
            $values = array_values($condition->value);

            return [$column . ' ' . $condition->operator . ' ? AND ?', $values];
        }

        return [$column . ' ' . $condition->operator . ' ?', [$condition->value]];
    }
}
