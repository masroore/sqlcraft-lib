<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class DumpScope
{
    /**
     * @param list<string>|null $tables
     */
    private function __construct(
        public ScopeKind $kind,
        public ?string $database = null,
        public ?array $tables = null,
        public ?string $resultSql = null,
    ) {
    }

    public static function allDatabases(): self
    {
        return new self(ScopeKind::AllDatabases);
    }

    public static function database(string $database): self
    {
        return new self(ScopeKind::Database, database: $database);
    }

    /** @param list<string> $tables */
    public static function tables(string $database, array $tables): self
    {
        return new self(ScopeKind::Tables, database: $database, tables: $tables);
    }

    public static function table(string $database, string $table): self
    {
        return new self(ScopeKind::Tables, database: $database, tables: [$table]);
    }

    public static function filteredResult(string $database, string $table, string $sql): self
    {
        return new self(
            ScopeKind::FilteredResult,
            database: $database,
            tables: [$table],
            resultSql: $sql,
        );
    }
}
