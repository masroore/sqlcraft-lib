<?php

declare(strict_types=1);

namespace SQLCraft\Platform;

use InvalidArgumentException;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValueKind;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\ServerVersion;

final class PostgreSQLPlatform extends AbstractPlatform
{
    #[\Override]
    public function getName(): string
    {
        return 'pgsql';
    }

    #[\Override]
    public function getFlavor(): ?string
    {
        return null;
    }

    #[\Override]
    public function getServerVersion(ConnectionInterface $connection): ServerVersion
    {
        $values = $connection->query('SHOW server_version')->fetchColumn();
        if (isset($values[0]) && is_string($values[0])) {
            return new ServerVersion($values[0]);
        }

        return new ServerVersion('9.0.0');
    }

    /**
     * @return array{always: list<Capability>, versioned: list<array{0: Capability, 1: array{0: int, 1: int, 2: int}}>}
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
                Capability::Comment,
                Capability::Collation,
                Capability::Processlist,
                Capability::Kill,
                Capability::Trigger,
                Capability::Routine,
                Capability::Sequence,
                Capability::Scheme,
                Capability::Type,
                Capability::CheckConstraints,
                Capability::PartialIndexes,
                Capability::DescendingIndexes,
                Capability::Partitions,
            ],
            'versioned' => [
                [Capability::MaterializedView, [9, 3, 0]],
                [Capability::GeneratedColumns, [12, 0, 0]],
                [Capability::Procedure, [11, 0, 0]],
            ],
        ];
    }

    #[\Override]
    public function getDefaultCharset(): string
    {
        return 'UTF8';
    }

    #[\Override]
    public function getDefaultCollation(): ?string
    {
        return null;
    }

    #[\Override]
    public function supportsSchemas(): bool
    {
        return true;
    }

    /** @return list<string> */
    #[\Override]
    public function getKeywordList(): array
    {
        return ['SELECT', 'FROM', 'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'OFFSET', 'RETURNING', 'WITH', 'CREATE', 'ALTER', 'DROP'];
    }

    #[\Override]
    public function quoteValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => throw new InvalidArgumentException('PostgreSQL values must be scalar or null.'),
        };
    }

    #[\Override]
    public function quoteBinary(string $bytes): string
    {
        return "decode('" . bin2hex($bytes) . "', 'hex')";
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
            throw new InvalidArgumentException('Pagination values must not be negative.');
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
            'float' => 'DOUBLE PRECISION',
            'bool' => 'BOOLEAN',
            'string' => 'TEXT',
            'null' => 'TEXT',
            default => 'BYTEA',
        };
    }

    /** @return list<string> */
    #[\Override]
    public function getSupportedTypes(): array
    {
        return [
            'SMALLINT', 'INTEGER', 'BIGINT', 'DECIMAL', 'NUMERIC', 'REAL', 'DOUBLE PRECISION',
            'BOOLEAN', 'CHAR', 'VARCHAR', 'TEXT', 'BYTEA', 'DATE', 'TIME', 'TIMESTAMP',
            'TIMESTAMPTZ', 'INTERVAL', 'UUID', 'JSON', 'JSONB', 'XML', 'ARRAY',
        ];
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
        return ['CHAR', 'VARCHAR', 'TEXT'];
    }

    #[\Override]
    public function renderColumnDefinition(ColumnMeta $column): string
    {
        $definition = $this->quoteIdentifier(new Identifier($column->name)) . ' ' . $this->renderDataType($column->dataType);
        if (!$column->nullable) {
            $definition .= ' NOT NULL';
        }
        if ($column->default->kind !== DefaultValueKind::NULL_VALUE) {
            $defaultValue = $column->default->value;
            if ($defaultValue === null) {
                throw new InvalidArgumentException('Non-NULL column defaults require a value.');
            }
            $definition .= ' DEFAULT ' . ($column->default->kind === DefaultValueKind::EXPRESSION
                ? $defaultValue
                : $this->quoteValue($defaultValue));
        }
        if ($column->autoIncrement) {
            $definition .= ' GENERATED BY DEFAULT AS IDENTITY';
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
            . ' FOREIGN KEY (' . $this->quoteList($foreignKey->sourceColumns) . ')'
            . ' REFERENCES ' . $this->quoteQualifiedParts($foreignKey->targetDatabase, $foreignKey->targetSchema, $foreignKey->targetTable)
            . ' (' . $this->quoteList($foreignKey->targetColumns) . ')'
            . ' ON DELETE ' . $foreignKey->onDelete->value
            . ' ON UPDATE ' . $foreignKey->onUpdate->value;
    }

    #[\Override]
    public function renderCheckConstraintClause(CheckConstraintMeta $check): string
    {
        return 'CONSTRAINT ' . $this->quoteIdentifier(new Identifier($check->name)) . ' CHECK (' . $check->expression . ')';
    }

    /**
     * @param list<string> $columnClauses
     * @param list<string> $constraintClauses
     * @param array<string, scalar|null> $tableOptions
     */
    #[\Override]
    public function renderCreateTableStatement(QualifiedName $table, array $columnClauses, array $constraintClauses, array $tableOptions): string
    {
        $sql = 'CREATE TABLE ' . $this->quoteQualifiedName($table) . ' (' . implode(', ', [...$columnClauses, ...$constraintClauses]) . ')';
        if (isset($tableOptions['tablespace']) && is_string($tableOptions['tablespace'])) {
            $sql .= ' TABLESPACE ' . $this->quoteIdentifier(new Identifier($tableOptions['tablespace']));
        }
        if (isset($tableOptions['with']) && is_string($tableOptions['with'])) {
            $sql .= ' WITH (' . $tableOptions['with'] . ')';
        }

        return $sql;
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
        $prefix = $index->unique ? 'UNIQUE ' : '';
        $using = in_array($index->type, [IndexType::GIN, IndexType::GIST, IndexType::BRIN], true)
            ? ' USING ' . strtolower($index->type->value)
            : '';

        return 'CREATE ' . $prefix . 'INDEX ' . $this->quoteIdentifier(new Identifier($index->name))
            . ' ON ' . $this->quoteQualifiedName($table) . $using . ' (' . $this->indexColumns($index) . ')'
            . ($index->filterExpression === null ? '' : ' WHERE ' . $index->filterExpression);
    }

    #[\Override]
    public function renderDropIndexStatement(QualifiedName $table, Identifier $indexName): string
    {
        return 'DROP INDEX ' . $this->quoteIdentifier($indexName);
    }

    #[\Override]
    public function getDatabasesSql(): string
    {
        return 'SELECT datname AS name FROM pg_database WHERE datistemplate = false ORDER BY datname';
    }

    #[\Override]
    public function getTablesSql(string $database, ?string $schema = null): string
    {
        $schemaFilter = $schema === null ? '' : ' AND table_schema = ' . $this->quoteValue($schema);
        return 'SELECT table_name AS name FROM information_schema.tables WHERE table_catalog = '
            . $this->quoteValue($database) . " AND table_type = 'BASE TABLE'" . $schemaFilter . ' ORDER BY table_name';
    }

    #[\Override]
    public function getColumnsSql(QualifiedName $table): string
    {
        return 'SELECT * FROM information_schema.columns WHERE table_name = ' . $this->quoteValue($table->object->name)
            . ($table->schema instanceof Identifier ? ' AND table_schema = ' . $this->quoteValue($table->schema->name) : '')
            . ' ORDER BY ordinal_position';
    }

    #[\Override]
    public function getIndexesSql(QualifiedName $table): string
    {
        return 'SELECT * FROM pg_indexes WHERE tablename = ' . $this->quoteValue($table->object->name)
            . ($table->schema instanceof Identifier ? ' AND schemaname = ' . $this->quoteValue($table->schema->name) : '');
    }

    #[\Override]
    public function getForeignKeysSql(QualifiedName $table): string
    {
        return 'SELECT * FROM information_schema.table_constraints WHERE table_name = '
            . $this->quoteValue($table->object->name) . " AND constraint_type = 'FOREIGN KEY'";
    }

    #[\Override]
    public function getReferencingForeignKeysSql(QualifiedName $table): string
    {
        return 'SELECT * FROM information_schema.key_column_usage WHERE referenced_table_name = '
            . $this->quoteValue($table->object->name)
            . ' AND referenced_column_name IS NOT NULL';
    }

    #[\Override]
    public function getTriggersSql(QualifiedName $table): string
    {
        return 'SELECT * FROM information_schema.triggers WHERE event_object_table = '
            . $this->quoteValue($table->object->name);
    }

    #[\Override]
    public function getRoutinesSql(?string $schema = null): string
    {
        return 'SELECT * FROM information_schema.routines'
            . ($schema === null ? '' : ' WHERE routine_schema = ' . $this->quoteValue($schema));
    }

    #[\Override]
    public function getSequencesSql(?string $schema = null): string
    {
        return 'SELECT sequence_schema, sequence_name FROM information_schema.sequences'
            . ($schema === null ? '' : ' WHERE sequence_schema = ' . $this->quoteValue($schema));
    }

    #[\Override]
    public function getVariablesSql(): string
    {
        throw CapabilityNotSupportedException::for(Capability::Variables, 'pgsql');
    }

    #[\Override]
    public function getProcesslistSql(): string
    {
        return 'SELECT pid, usename, datname, state, query FROM pg_stat_activity';
    }

    private function renderDataType(DataType $dataType): string
    {
        $type = strtoupper($dataType->name);
        if ($dataType->length !== null) {
            $type .= '(' . $dataType->length . ')';
        } elseif ($dataType->precision !== null) {
            $type .= '(' . $dataType->precision . ($dataType->scale === null ? '' : ', ' . $dataType->scale) . ')';
        }

        return $type;
    }

    /** @param list<string> $columns */
    private function quoteList(array $columns): string
    {
        return implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier(new Identifier($column)),
            $columns,
        ));
    }

    private function quoteQualifiedName(QualifiedName $name): string
    {
        return $this->quoteQualifiedParts(
            $name->catalog?->name,
            $name->schema?->name,
            $name->object->name,
        );
    }

    private function quoteQualifiedParts(?string $catalog, ?string $schema, string $object): string
    {
        $parts = [];
        if ($catalog !== null) {
            $parts[] = $this->quoteIdentifier(new Identifier($catalog));
        }
        if ($schema !== null) {
            $parts[] = $this->quoteIdentifier(new Identifier($schema));
        }
        $parts[] = $this->quoteIdentifier(new Identifier($object));

        return implode('.', $parts);
    }

    private function indexColumns(IndexMeta $index): string
    {
        return implode(', ', array_map(
            fn (\SQLCraft\DTO\IndexColumnMeta $column): string => $column->expression
                ?? $this->quoteIdentifier(new Identifier($column->columnName)) . ($column->descending ? ' DESC' : ' ASC'),
            $index->columns,
        ));
    }
}
