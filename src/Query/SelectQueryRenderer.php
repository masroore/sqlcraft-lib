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
            $conditionRenderer = new WhereConditionRenderer($this->platform);
            foreach ($query->where as $condition) {
                [$clause, $values] = $conditionRenderer->render($condition);
                $clauses[] = $clause;
                /** @psalm-suppress MixedAssignment */
                foreach ($values as $value) {
                    $params[] = $value;
                }
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($query->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn (string $column): string => $this->platform->quoting()->quoteIdentifier(new Identifier($column)), $query->groupBy));
        }
        if ($query->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(fn (OrderByClause $clause): string => $this->platform->quoting()->quoteIdentifier($clause->column) . ($clause->descending ? ' DESC' : ' ASC'), $query->orderBy));
        }
        if ($query->limit !== null) {
            $sql = $this->platform->queryDialect()->applyPagination($sql, $query->limit, $query->offset ?? 0);
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /** @param list<ColumnSelection> $columns */
    private function renderColumns(array $columns): string
    {
        if ($columns === []) {
            return '*';
        }
        $allowedAggregates = $this->platform->queryDialect()->getSupportedAggregateFunctions();

        return implode(', ', array_map(function (ColumnSelection $selection) use ($allowedAggregates): string {
            $column = $this->platform->quoting()->quoteIdentifier($selection->column);
            if ($selection->aggregateFunction !== null) {
                $aggregate = strtoupper($selection->aggregateFunction);
                if (! in_array($aggregate, $allowedAggregates, true)) {
                    throw new InvalidArgumentException(sprintf('Unsupported aggregate function: %s', $aggregate));
                }
                $column = $aggregate . '(' . $column . ')';
            }
            if ($selection->alias instanceof Identifier) {
                $column .= ' AS ' . $this->platform->quoting()->quoteIdentifier($selection->alias);
            }

            return $column;
        }, $columns));
    }

    private function quoteQualifiedName(QualifiedName $name): string
    {
        $parts = [];
        if ($name->catalog instanceof Identifier) {
            $parts[] = $this->platform->quoting()->quoteIdentifier($name->catalog);
        }
        if ($name->schema instanceof Identifier) {
            $parts[] = $this->platform->quoting()->quoteIdentifier($name->schema);
        }
        $parts[] = $this->platform->quoting()->quoteIdentifier($name->object);

        return implode('.', $parts);
    }
}
