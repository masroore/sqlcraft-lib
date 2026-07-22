<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Schema\SchemaManager;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class TableSearchService
{
    public function __construct(private SchemaManager $schema, private QueryExecutorInterface $executor, private int $rowCap = 1000)
    {
        if ($rowCap < 1) {
            throw new \InvalidArgumentException('Row cap must be >= 1.');
        }
    }

    /** @return list<array{table: string, rows: list<array<string, mixed>>}> */
    public function search(ConnectionInterface $connection, string $term, ?string $schema = null): array
    {
        $connection->getPlatform()->getCapabilitySet($connection->getServerVersion())->require(Capability::CrossTableSearch);
        $results = [];
        foreach ($this->schema->getTables($connection, $schema) as $table) {
            $columns = $this->schema->getColumns($connection, new QualifiedName(new Identifier($table->name), $schema === null ? null : new Identifier($schema)));
            $text = array_filter(
                $columns->map(static fn ($column): string => $column->name),
                static fn (string $value): bool => $value !== '',
            );
            if ($text === []) {
                continue;
            }
            $where = implode(' OR ', array_map(fn (string $column): string => $connection->quoteIdentifier($column) . ' LIKE ?', $text));
            $rows = $this->executor->query($connection, 'SELECT * FROM ' . $connection->quoteIdentifier($table->name) . ' WHERE ' . $where . ' LIMIT ' . $this->rowCap, array_fill(0, count($text), '%' . $term . '%'), buffered: true)->fetchAll();
            if ($rows !== []) {
                $results[] = ['table' => $table->name, 'rows' => $rows];
            }
        }

        return $results;
    }
}
