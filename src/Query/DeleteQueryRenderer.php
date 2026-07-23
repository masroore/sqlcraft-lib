<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\Identifier;

final readonly class DeleteQueryRenderer
{
    public function __construct(private PlatformInterface $platform)
    {
    }

    /** @return array{sql: string, params: list<mixed>} */
    public function render(DeleteQuery $query): array
    {
        $parts = [];
        if ($query->table->schema instanceof Identifier) {
            $parts[] = $this->platform->quoting()->quoteIdentifier($query->table->schema);
        }
        $parts[] = $this->platform->quoting()->quoteIdentifier($query->table->object);

        $sql = 'DELETE FROM ' . implode('.', $parts);
        $params = [];
        $where = [];
        $renderer = new WhereConditionRenderer($this->platform);
        foreach ($query->where as $condition) {
            [$clause, $values] = $renderer->render($condition);
            $where[] = $clause;
            /** @psalm-suppress MixedAssignment */
            foreach ($values as $value) {
                $params[] = $value;
            }
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        if ($query->limit !== null) {
            $sql .= ' LIMIT ' . $query->limit;
        }

        return ['sql' => $sql, 'params' => $params];
    }
}
