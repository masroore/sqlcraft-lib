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
        $columns = implode(', ', array_map(fn (string $name): string => $this->platform->quoting()->quoteIdentifier(new Identifier($name)), $query->columns));
        if ($query->selectSql !== null) {
            return ['sql' => 'INSERT INTO ' . $this->quoteTable($query) . ' (' . $columns . ') ' . $query->selectSql, 'params' => []];
        }
        $row = '(' . implode(', ', array_fill(0, count($query->columns), '?')) . ')';
        $values = [];
        foreach ($query->rows as $valuesRow) {
            $values = [...$values, ...$valuesRow];
        }
        /** @var list<mixed> $values */
        $quotedColumns = array_map(fn (string $name): string => $this->platform->quoting()->quoteIdentifier(new Identifier($name)), $query->columns);
        $clauses = UpsertSqlRenderer::clauses($this->platform, $query->upsertMode, $quotedColumns);
        if ($this->platform->getName() === 'sqlserver' && $query->upsertMode !== UpsertMode::Insert) {
            $sourceColumns = implode(', ', $quotedColumns);
            $sourceValues = implode(', ', array_fill(0, count($query->rows), $row));
            $key = $quotedColumns[0] ?? throw new \InvalidArgumentException('Upsert requires at least one column.');
            $assignments = implode(', ', array_map(static fn (string $column): string => 'target.' . $column . ' = source.' . $column, $quotedColumns));
            $insertValues = implode(', ', array_map(static fn (string $column): string => 'source.' . $column, $quotedColumns));
            $sql = 'MERGE INTO ' . $this->quoteTable($query) . ' AS target USING (VALUES ' . $sourceValues . ') AS source (' . $sourceColumns . ') ON target.' . $key . ' = source.' . $key
                . ' WHEN NOT MATCHED THEN INSERT (' . $sourceColumns . ') VALUES (' . $insertValues . ')';
            if ($query->upsertMode === UpsertMode::InsertOrReplace) {
                $sql .= ' WHEN MATCHED THEN UPDATE SET ' . $assignments;
            }

            return ['sql' => $sql . ';', 'params' => $values];
        }

        $sql = $clauses['prefix'] . ' INTO ' . $this->quoteTable($query) . ' (' . $columns . ') VALUES ' . implode(', ', array_fill(0, count($query->rows), $row)) . $clauses['suffix'];

        return ['sql' => $sql, 'params' => $values];
    }

    private function quoteTable(InsertQuery $query): string
    {
        $parts = [];
        if ($query->table->schema instanceof Identifier) {
            $parts[] = $this->platform->quoting()->quoteIdentifier($query->table->schema);
        } $parts[] = $this->platform->quoting()->quoteIdentifier($query->table->object);

        return implode('.', $parts);
    }
}
