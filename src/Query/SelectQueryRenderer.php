<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use InvalidArgumentException;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class SelectQueryRenderer
{
    public function __construct(private PlatformInterface $platform)
    {
    }

    /** @return array{sql: string, params: list<mixed>} */
    public function render(SelectQuery $query): array
    {
        $columns = $this->renderColumns($query->columns);
        $sql = 'SELECT ' . ($query->distinct ? 'DISTINCT ' : '') . $columns . ' FROM ' . $this->quoteQualifiedName($query->table);
        $params = [];

        if ($query->where !== []) {
            $clauses = [];
            foreach ($query->where as $condition) {
                if (!in_array($condition->operator, $this->platform->getOperators(), true)) {
                    throw new InvalidArgumentException(sprintf('Operator %s is not supported by %s.', $condition->operator, $this->platform->getName()));
                }
                [$clause, $values] = $this->renderCondition($condition);
                $clauses[] = $clause;
                $params = [...$params, ...$values];
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($query->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn (string $column): string => $this->platform->quoteIdentifier(new Identifier($column)), $query->groupBy));
        }
        if ($query->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(fn (OrderByClause $clause): string => $this->platform->quoteIdentifier($clause->column) . ($clause->descending ? ' DESC' : ' ASC'), $query->orderBy));
        }
        if ($query->limit !== null) {
            $sql = $this->platform->applyPagination($sql, $query->limit, $query->offset ?? 0);
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /** @param list<ColumnSelection> $columns */
    private function renderColumns(array $columns): string
    {
        if ($columns === []) {
            return '*';
        }
        $allowedAggregates = $this->platform->getSupportedAggregateFunctions();

        return implode(', ', array_map(function (ColumnSelection $selection) use ($allowedAggregates): string {
            $column = $this->platform->quoteIdentifier($selection->column);
            if ($selection->aggregateFunction !== null) {
                $aggregate = strtoupper($selection->aggregateFunction);
                if (!in_array($aggregate, $allowedAggregates, true)) {
                    throw new InvalidArgumentException(sprintf('Unsupported aggregate function: %s', $aggregate));
                }
                $column = $aggregate . '(' . $column . ')';
            }
            if ($selection->alias instanceof \SQLCraft\ValueObjects\Identifier) {
                $column .= ' AS ' . $this->platform->quoteIdentifier($selection->alias);
            }

            return $column;
        }, $columns));
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function renderCondition(WhereCondition $condition): array
    {
        $column = $this->platform->quoteIdentifier($condition->column);
        if (in_array($condition->operator, ['IS NULL', 'IS NOT NULL'], true)) {
            return [$column . ' ' . $condition->operator, []];
        }
        if (in_array($condition->operator, ['IN', 'NOT IN'], true)) {
            if (!is_array($condition->value) || $condition->value === []) {
                throw new InvalidArgumentException('IN conditions require a non-empty list of values.');
            }
            return [$column . ' ' . $condition->operator . ' (' . implode(', ', array_fill(0, count($condition->value), '?')) . ')', array_values($condition->value)];
        }
        if (in_array($condition->operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
            if (!is_array($condition->value) || count($condition->value) !== 2) {
                throw new InvalidArgumentException('BETWEEN conditions require exactly two values.');
            }
            return [$column . ' ' . $condition->operator . ' ? AND ?', array_values($condition->value)];
        }

        return [$column . ' ' . $condition->operator . ' ?', [$condition->value]];
    }

    private function quoteQualifiedName(QualifiedName $name): string
    {
        $parts = [];
        if ($name->catalog instanceof Identifier) {
            $parts[] = $this->platform->quoteIdentifier($name->catalog);
        }
        if ($name->schema instanceof Identifier) {
            $parts[] = $this->platform->quoteIdentifier($name->schema);
        }
        $parts[] = $this->platform->quoteIdentifier($name->object);

        return implode('.', $parts);
    }
}
