<?php

declare(strict_types=1);

namespace SQLCraft\Platform;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\ServerVersion;

/** @internal Minimal SQLite dialect used by M2 connection tests. */
final class SqlitePlatform extends AbstractPlatform
{
    #[\Override]
    public function getName(): string
    {
        return 'sqlite';
    }

    #[\Override]
    public function getFlavor(): ?string
    {
        return null;
    }

    #[\Override]
    public function getServerVersion(ConnectionInterface $connection): ServerVersion
    {
        $values = $connection->query('SELECT sqlite_version()')->fetchColumn();
        if (isset($values[0]) && is_string($values[0])) {
            return new ServerVersion($values[0]);
        }

        return new ServerVersion('3.0.0');
    }

    /**
     * @return array{always: list<\SQLCraft\Capabilities\Capability>, versioned: list<array{0: \SQLCraft\Capabilities\Capability, 1: array{0: int, 1: int, 2: int}}>}
     */
    #[\Override]
    protected function buildCapabilityMatrix(): array
    {
        return [
            'always' => [
                Capability::Table,
                Capability::View,
                Capability::Columns,
                Capability::Indexes,
                Capability::ForeignKeys,
                Capability::Sql,
                Capability::Database,
                Capability::DropColumn,
                Capability::Dump,
                Capability::Status,
                Capability::Variables,
                Capability::Trigger,
                Capability::CheckConstraints,
                Capability::DescendingIndexes,
                Capability::PartialIndexes,
                Capability::InsertUpdate,
            ],
            'versioned' => [
                [Capability::GeneratedColumns, [3, 31, 0]],
            ],
        ];
    }

    #[\Override]
    public function getDefaultCharset(): ?string
    {
        return null;
    }

    #[\Override]
    public function getDefaultCollation(): ?string
    {
        return null;
    }

    #[\Override]
    public function supportsSchemas(): bool
    {
        return false;
    }

    /** @return list<string> */
    #[\Override]
    public function getKeywordList(): array
    {
        return ['SELECT', 'FROM', 'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'OFFSET'];
    }

    #[\Override]
    public function quoteIdentifier(Identifier $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier->name) . '"';
    }

    #[\Override]
    public function quoteValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => throw new \InvalidArgumentException('SQLite values must be scalar or null.'),
        };
    }

    #[\Override]
    public function quoteBinary(string $bytes): string
    {
        return "X'" . bin2hex($bytes) . "'";
    }

    #[\Override]
    public function convertFieldIn(ColumnMeta $column, string $expression): string
    {
        return $expression;
    }

    #[\Override]
    public function convertFieldOut(ColumnMeta $column, string $expression): string
    {
        return $expression;
    }

    #[\Override]
    public function applyPagination(string $sql, int $limit, int $offset): string
    {
        if ($limit < 0 || $offset < 0) {
            throw new \InvalidArgumentException('Pagination values must not be negative.');
        }

        return $sql . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }

    #[\Override]
    public function applySingleRowLimit(string $sql, string $whereClause): string
    {
        return $sql . ($whereClause === '' ? '' : ' ' . $whereClause) . ' LIMIT 1';
    }

    #[\Override]
    public function mapPhpTypeToDb(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'INTEGER',
            'float' => 'REAL',
            'bool' => 'INTEGER',
            'string' => 'TEXT',
            'null' => 'NULL',
            default => 'BLOB',
        };
    }

    /** @return list<string> */
    #[\Override]
    public function getSupportedTypes(): array
    {
        return ['INTEGER', 'REAL', 'TEXT', 'BLOB', 'NULL'];
    }

    /** @return list<string> */
    #[\Override]
    public function getUnsignedTypes(): array
    {
        return [];
    }

    /** @return list<string> */
    #[\Override]
    public function getCollatableTypes(): array
    {
        return ['TEXT'];
    }

    #[\Override]
    public function renderColumnDefinition(ColumnMeta $column): string
    {
        $definition = $this->quoteIdentifier(new Identifier($column->name)) . ' ' . $column->dataType->name;
        if ($column->primary) {
            $definition .= ' PRIMARY KEY';
        }
        if (!$column->nullable) {
            $definition .= ' NOT NULL';
        }
        if ($column->autoIncrement) {
            $definition .= ' AUTOINCREMENT';
        }
        return $definition;
    }

    #[\Override]
    public function renderPrimaryKeyClause(IndexMeta $index): string
    {
        return 'PRIMARY KEY (' . $this->indexColumns($index) . ')';
    }

    #[\Override]
    public function renderForeignKeyClause(ForeignKeyMeta $foreignKey): string
    {
        return 'CONSTRAINT ' . $this->quoteIdentifier(new Identifier($foreignKey->constraintName))
            . ' FOREIGN KEY (' . implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier(new Identifier($column)), $foreignKey->sourceColumns)) . ')'
            . ' REFERENCES ' . $this->quoteIdentifier(new Identifier($foreignKey->targetTable))
            . ' (' . implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier(new Identifier($column)), $foreignKey->targetColumns)) . ')'
            . ' ON DELETE ' . $foreignKey->onDelete->value
            . ' ON UPDATE ' . $foreignKey->onUpdate->value;
    }

    #[\Override]
    public function renderCheckConstraintClause(CheckConstraintMeta $check): string
    {
        return 'CONSTRAINT ' . $this->quoteIdentifier(new Identifier($check->name)) . ' CHECK (' . $check->expression . ')';
    }

    /** @param list<string> $columnClauses @param list<string> $constraintClauses @param array<string, scalar|null> $tableOptions */
    #[\Override]
    public function renderCreateTableStatement(QualifiedName $table, array $columnClauses, array $constraintClauses, array $tableOptions): string
    {
        /** @var list<string> $clauses */
        $clauses = [...$columnClauses, ...$constraintClauses];

        return 'CREATE TABLE ' . $this->quoteQualifiedName($table) . ' (' . implode(', ', $clauses) . ')';
    }

    #[\Override]
    public function renderAlterTableAddColumn(QualifiedName $table, ColumnMeta $column): string
    {
        return 'ALTER TABLE ' . $this->quoteQualifiedName($table) . ' ADD COLUMN ' . $this->renderColumnDefinition($column);
    }

    #[\Override]
    public function renderAlterTableDropColumn(QualifiedName $table, Identifier $column): string
    {
        return 'ALTER TABLE ' . $this->quoteQualifiedName($table) . ' DROP COLUMN ' . $this->quoteIdentifier($column);
    }

    #[\Override]
    public function renderCreateIndexStatement(QualifiedName $table, IndexMeta $index): string
    {
        $unique = $index->unique ? 'UNIQUE ' : '';
        return 'CREATE ' . $unique . 'INDEX ' . $this->quoteIdentifier(new Identifier($index->name)) . ' ON ' . $this->quoteQualifiedName($table) . ' (' . $this->indexColumns($index) . ')';
    }

    #[\Override]
    public function renderDropIndexStatement(QualifiedName $table, Identifier $indexName): string
    {
        return 'DROP INDEX ' . $this->quoteIdentifier($indexName);
    }

    #[\Override]
    public function getDatabasesSql(): string
    {
        return "SELECT 'main' AS name UNION ALL SELECT 'temp' AS name";
    }
    #[\Override]
    public function getSchemasSql(): string
    {
        return '';
    }

    #[\Override]
    public function getTypesSql(?string $schema = null): string
    {
        throw CapabilityNotSupportedException::for(Capability::Type, 'sqlite');
    }

    #[\Override]
    public function getTablesSql(string $database, ?string $schema = null): string
    {
        return $this->tableStatusSql($this->catalog($database), null);
    }

    #[\Override]
    public function getTableStatusSql(QualifiedName $table): string
    {
        return $this->tableStatusSql($this->catalog($table->catalog?->name), $table->object->name);
    }

    #[\Override]
    public function getParentTablesSql(QualifiedName $table): string
    {
        return '';
    }

    #[\Override]
    public function getPartitionsSql(QualifiedName $table): string
    {
        throw CapabilityNotSupportedException::for(Capability::Partitions, 'sqlite');
    }

    #[\Override]
    public function getViewsSql(?string $schema = null): string
    {
        return "SELECT name AS view_name, NULL AS table_schema, sql AS view_definition, 0 AS materialized FROM main.sqlite_master WHERE type = 'view' ORDER BY name";
    }

    #[\Override]
    public function getViewDefinitionSql(QualifiedName $view): string
    {
        return "SELECT sql AS definition FROM main.sqlite_master WHERE type = 'view' AND name = " . $this->quoteValue($view->object->name);
    }

    #[\Override]
    public function getMaterializedViewsSql(?string $schema = null): string
    {
        throw CapabilityNotSupportedException::for(Capability::MaterializedView, 'sqlite');
    }

    #[\Override]
    public function getColumnsSql(QualifiedName $table): string
    {
        return 'PRAGMA table_info(' . $this->quoteIdentifier($table->object) . ')';
    }
    #[\Override]
    public function getIndexesSql(QualifiedName $table): string
    {
        return 'PRAGMA index_list(' . $this->quoteIdentifier($table->object) . ')';
    }
    #[\Override]
    public function getForeignKeysSql(QualifiedName $table): string
    {
        return 'PRAGMA foreign_key_list(' . $this->quoteIdentifier($table->object) . ')';
    }
    #[\Override]
    public function getReferencingForeignKeysSql(QualifiedName $table): string
    {
        throw CapabilityNotSupportedException::for(Capability::ForeignKeys, 'sqlite');
    }

    #[\Override]
    public function getTriggersSql(QualifiedName $table): string
    {
        return "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name";
    }
    #[\Override]
    public function getRoutineDetailSql(QualifiedName $routine): string
    {
        throw CapabilityNotSupportedException::for(Capability::Routine, 'sqlite');
    }

    #[\Override]
    public function getCheckConstraintsSql(QualifiedName $table): string
    {
        throw CapabilityNotSupportedException::for(Capability::CheckConstraints, 'sqlite');
    }

    #[\Override]
    public function getUsersSql(): string
    {
        throw CapabilityNotSupportedException::for(Capability::Privileges, 'sqlite');
    }

    #[\Override]
    public function getRoutinesSql(?string $schema = null): string
    {
        return "SELECT name, sql FROM sqlite_master WHERE type IN ('trigger', 'view') ORDER BY name";
    }
    #[\Override]
    public function getSequencesSql(?string $schema = null): string
    {
        throw CapabilityNotSupportedException::for(Capability::Sequence, 'sqlite');
    }
    #[\Override]
    public function getVariablesSql(): string
    {
        return 'PRAGMA compile_options';
    }
    #[\Override]
    public function getStatusSql(): string
    {
        return '';
    }

    #[\Override]
    public function getCharsetsSql(): string
    {
        throw CapabilityNotSupportedException::for(Capability::Charset, 'sqlite');
    }

    #[\Override]
    public function getCollationsSql(?string $charset = null): string
    {
        throw CapabilityNotSupportedException::for(Capability::Collation, 'sqlite');
    }

    #[\Override]
    public function getProcesslistSql(): string
    {
        throw CapabilityNotSupportedException::for(Capability::Processlist, 'sqlite');
    }

    private function tableStatusSql(string $database, ?string $table): string
    {
        $quotedDatabase = $this->quoteIdentifier(new Identifier($database));
        $sql = "SELECT name AS table_name, type AS table_type, "
            . "CASE WHEN type = 'view' THEN 1 ELSE 0 END AS is_view "
            . "FROM {$quotedDatabase}.sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'";

        if ($table !== null) {
            $sql .= ' AND name = ' . $this->quoteValue($table);
        }

        return $sql . ' ORDER BY name';
    }

    private function catalog(?string $database): string
    {
        return in_array($database, ['main', 'temp'], true) ? $database : 'main';
    }

    private function quoteQualifiedName(QualifiedName $name): string
    {
        $parts = [];
        if ($name->catalog instanceof Identifier) {
            $parts[] = $this->quoteIdentifier($name->catalog);
        }
        if ($name->schema instanceof Identifier) {
            $parts[] = $this->quoteIdentifier($name->schema);
        }
        $parts[] = $this->quoteIdentifier($name->object);
        return implode('.', $parts);
    }

    private function indexColumns(IndexMeta $index): string
    {
        return implode(', ', array_map(
            fn (\SQLCraft\DTO\IndexColumnMeta $column): string => $column->expression ?? $this->quoteIdentifier(new Identifier($column->columnName)) . ($column->descending ? ' DESC' : ' ASC'),
            $index->columns,
        ));
    }
}
