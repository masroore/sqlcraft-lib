<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\Identifier;

final readonly class UpdateQueryRenderer
{
    public function __construct(private PlatformInterface $platform)
    {
    }

    /** @return array{sql: string, params: list<mixed>} */
    public function render(UpdateQuery $query): array
    {
        $params = [];
        $assignments = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($query->assignments as $column => $value) {
            $assignments[] = $this->platform->quoteIdentifier(new Identifier($column)) . ' = ?';
            /** @psalm-suppress MixedAssignment */
            $params[] = $value;
        }

        $sql = 'UPDATE ' . $this->table($query) . ' SET ' . implode(', ', $assignments);
        /** @var array{0: string, 1: list<mixed>} $whereResult */
        $whereResult = $this->where($query->where);
        [$where, $values] = $whereResult;
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
            /** @psalm-suppress MixedAssignment */
            foreach ($values as $value) {
                $params[] = $value;
            }
        }
        if ($query->limit !== null) {
            $sql .= ' LIMIT ' . $query->limit;
        }

        /** @var list<mixed> $params */
        return ['sql' => $sql, 'params' => $params];
    }

    private function table(UpdateQuery $query): string
    {
        $parts = [];
        if ($query->table->schema instanceof \SQLCraft\ValueObjects\Identifier) {
            $parts[] = $this->platform->quoteIdentifier($query->table->schema);
        }
        $parts[] = $this->platform->quoteIdentifier($query->table->object);

        return implode('.', $parts);
    }

    /**
     * @param list<WhereCondition> $conditions
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $conditions): array
    {
        $parts = [];
        $params = [];
        $renderer = new WhereConditionRenderer($this->platform);
        foreach ($conditions as $condition) {
            [$clause, $values] = $renderer->render($condition);
            $parts[] = $clause;
            /** @psalm-suppress MixedAssignment */
            foreach ($values as $value) {
                $params[] = $value;
            }
        }

        return [implode(' AND ', $parts), $params];
    }
}
