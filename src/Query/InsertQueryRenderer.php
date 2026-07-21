<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Import\UpsertMode;
use SQLCraft\ValueObjects\Identifier;

final readonly class InsertQueryRenderer
{
    public function __construct(private PlatformInterface $platform)
    {
    }

    /** @return array{sql: string, params: list<mixed>} */
    public function render(InsertQuery $query): array
    {
        $columns = implode(', ', array_map(fn (string $name): string => $this->platform->quoteIdentifier(new Identifier($name)), $query->columns));
        if ($query->selectSql !== null) {
            return ['sql' => 'INSERT INTO ' . $this->quoteTable($query) . ' (' . $columns . ') ' . $query->selectSql, 'params' => []];
        }
        $row = '(' . implode(', ', array_fill(0, count($query->columns), '?')) . ')';
        $values = [];
        foreach ($query->rows as $valuesRow) {
            $values = [...$values, ...$valuesRow];
        }
        /** @var list<mixed> $values */
        $prefix = match ($query->upsertMode) {
            UpsertMode::Insert => 'INSERT',
            UpsertMode::InsertOrIgnore => match ($this->platform->getName()) {
                'sqlite' => 'INSERT OR IGNORE', 'mysql', 'mariadb' => 'INSERT IGNORE', default => 'INSERT'
            },
            UpsertMode::InsertOrReplace => match ($this->platform->getName()) {
                'sqlite' => 'INSERT OR REPLACE', 'mysql', 'mariadb' => 'REPLACE', default => 'INSERT'
            },
        };
        $sql = $prefix . ' INTO ' . $this->quoteTable($query) . ' (' . $columns . ') VALUES ' . implode(', ', array_fill(0, count($query->rows), $row));
        if ($query->upsertMode === UpsertMode::InsertOrIgnore && $this->platform->getName() === 'pgsql') {
            $sql .= ' ON CONFLICT DO NOTHING';
        }

        return ['sql' => $sql, 'params' => $values];
    }
    private function quoteTable(InsertQuery $query): string
    {
        $parts = [];
        if ($query->table->schema !== null) {
            $parts[] = $this->platform->quoteIdentifier($query->table->schema);
        } $parts[] = $this->platform->quoteIdentifier($query->table->object);
        return implode('.', $parts);
    }
}
