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
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\ServerVersion;

/** SQL Server dialect. */
final class SqlServerPlatform extends AbstractPlatform
{
    /** @return list<string> */
    #[\Override]
    public function getOperators(): array
    {
        return [
            '=', '!=', '<>', '<', '<=', '>', '>=',
            'LIKE', 'NOT LIKE', 'IN', 'NOT IN',
            'IS NULL', 'IS NOT NULL', 'BETWEEN', 'NOT BETWEEN',
        ];
    }

    #[\Override]
    public function getExplainSql(string $sql, bool $analyze = false): string
    {
        return 'SET SHOWPLAN_ALL ON; ' . $sql . '; SET SHOWPLAN_ALL OFF';
    }

    #[\Override]
    public function getName(): string
    {
        return 'sqlserver';
    }

    #[\Override]
    public function getFlavor(): ?string
    {
        return null;
    }

    #[\Override]
    public function getServerVersion(ConnectionInterface $connection): ServerVersion
    {
        $values = $connection->query("SELECT CONVERT(varchar(128), SERVERPROPERTY('ProductVersion'))")->fetchColumn();
        if (isset($values[0]) && is_string($values[0])) {
            $version = preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $values[0]);
            return new ServerVersion(is_string($version) ? $version : $values[0]);
        }

        return new ServerVersion('11.0.0');
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
                Capability::Charset,
                Capability::Collation,
                Capability::Status,
                Capability::Variables,
                Capability::Processlist,
                Capability::Kill,
                Capability::Privileges,
                Capability::Trigger,
                Capability::ViewTrigger,
                Capability::Routine,
                Capability::Procedure,
                Capability::CheckConstraints,
                Capability::DescendingIndexes,
                Capability::GeneratedColumns,
                Capability::Scheme,
                Capability::Type,
                Capability::InsertUpdate,
            ],
            'versioned' => [
                [Capability::Sequence, [11, 0, 0]],
            ],
        ];
    }

    #[\Override]
    public function getDefaultCharset(): string
    {
        return 'UTF-8';
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
        return ['SELECT', 'FROM', 'WHERE', 'GROUP', 'ORDER', 'TOP', 'OFFSET', 'FETCH', 'CREATE', 'ALTER', 'DROP'];
    }

    #[\Override]
    public function quoteIdentifier(Identifier $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier->name) . ']';
    }

    #[\Override]
    public function quoteValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'",
            default => throw new InvalidArgumentException('SQL Server values must be scalar or null.'),
        };
    }

    #[\Override]
    public function quoteBinary(string $bytes): string
    {
        return "0x" . bin2hex($bytes);
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

        return $sql . ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY';
    }

    #[\Override]
    public function applySingleRowLimit(string $sql, string $whereClause): string
    {
        return 'SELECT TOP 1 * FROM (' . $sql . ($whereClause === '' ? '' : ' ' . $whereClause) . ') AS [sqlcraft_single_row]';
    }

    #[\Override]
    public function mapPhpTypeToDb(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'BIGINT',
            'float' => 'FLOAT',
            'bool' => 'BIT',
            'string' => 'NVARCHAR(255)',
            'null' => 'NVARCHAR(MAX)',
            default => 'VARBINARY(MAX)',
        };
    }

    /** @return list<string> */
    #[\Override]
    public function getSupportedTypes(): array
    {
        return [
            'BIT', 'TINYINT', 'SMALLINT', 'INT', 'INTEGER', 'BIGINT',
            'DECIMAL', 'NUMERIC', 'FLOAT', 'REAL',
            'CHAR', 'VARCHAR', 'NCHAR', 'NVARCHAR', 'TEXT', 'NTEXT',
            'BINARY', 'VARBINARY', 'IMAGE',
            'DATE', 'DATETIME', 'DATETIME2', 'SMALLDATETIME', 'DATETIMEOFFSET', 'TIME', 'TIMESTAMP', 'UNIQUEIDENTIFIER', 'XML', 'GEOGRAPHY', 'GEOMETRY',
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
        return ['CHAR', 'VARCHAR', 'NCHAR', 'NVARCHAR', 'TEXT', 'NTEXT'];
    }

    #[\Override]
    public function renderCreateSequenceStatement(
        \SQLCraft\ValueObjects\Identifier $name,
        int $start,
        int $increment,
        ?int $min,
        ?int $max,
        bool $cycle,
        ?int $cache,
    ): string {
        return parent::renderCreateSequenceStatement($name, $start, $increment, $min, $max, $cycle, $cache);
    }

    #[\Override]
    public function renderDropSequenceStatement(\SQLCraft\ValueObjects\Identifier $name, bool $ifExists): string
    {
        return 'DROP SEQUENCE' . ($ifExists ? ' IF EXISTS' : '') . ' ' . $this->quoteIdentifier($name);
    }

    #[\Override]
    public function renderColumnDefinition(ColumnMeta $column): string
    {
        $dataType = $column->dataType;
        $definition = $this->quoteIdentifier(new Identifier($column->name)) . ' ' . $this->renderDataType($dataType);
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
            $definition .= ' IDENTITY(1,1)';
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
        $clauses = [...$columnClauses, ...$constraintClauses];

        return 'CREATE TABLE ' . $this->quoteQualifiedName($table) . ' (' . implode(', ', $clauses) . ')';
    }

    #[\Override]
    public function renderAlterTableAddColumn(QualifiedName $table, ColumnMeta $column): string
    {
        return 'ALTER TABLE ' . $this->quoteQualifiedName($table) . ' ADD ' . $this->renderColumnDefinition($column);
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

        return 'CREATE ' . $prefix . 'INDEX ' . $this->quoteIdentifier(new Identifier($index->name))
            . ' ON ' . $this->quoteQualifiedName($table) . ' (' . $this->indexColumns($index) . ')';
    }

    #[\Override]
    public function renderDropIndexStatement(QualifiedName $table, Identifier $indexName): string
    {
        return 'DROP INDEX ' . $this->quoteIdentifier($indexName) . ' ON ' . $this->quoteQualifiedName($table);
    }

    #[\Override]
    public function getDatabasesSql(): string
    {
        return 'SELECT name AS database_name FROM sys.databases ORDER BY name';
    }

    #[\Override]
    public function getSchemasSql(): string
    {
        return 'SELECT SCHEMA_NAME AS schema_name FROM INFORMATION_SCHEMA.SCHEMATA ORDER BY SCHEMA_NAME';
    }

    #[\Override]
    public function getTypesSql(?string $schema = null): string
    {
        return 'SELECT DISTINCT DATA_TYPE AS type_name FROM INFORMATION_SCHEMA.COLUMNS' . ($schema === null ? '' : ' WHERE TABLE_SCHEMA = ' . $this->quoteValue($schema)) . ' ORDER BY DATA_TYPE';
    }

    #[\Override]
    public function getTablesSql(string $database, ?string $schema = null): string
    {
        return 'SELECT TABLE_NAME AS table_name, TABLE_TYPE AS table_type, TABLE_SCHEMA AS table_schema FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_CATALOG = ' . $this->quoteValue($database) . ($schema === null ? '' : ' AND TABLE_SCHEMA = ' . $this->quoteValue($schema)) . ' ORDER BY TABLE_SCHEMA, TABLE_NAME';
    }

    #[\Override]
    public function getTableStatusSql(QualifiedName $table): string
    {
        return 'SELECT TABLE_NAME AS table_name, TABLE_TYPE AS table_type, TABLE_SCHEMA AS table_schema FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_CATALOG = ' . $this->quoteValue($this->databaseName($table)) . ' AND TABLE_SCHEMA = ' . $this->quoteValue($this->schemaName($table)) . ' AND TABLE_NAME = ' . $this->quoteValue($table->object->name);
    }

    #[\Override]
    public function getParentTablesSql(QualifiedName $table): string
    {
        return '';
    }

    #[\Override]
    public function getPartitionsSql(QualifiedName $table): string
    {
        return '';
    }

    #[\Override]
    public function getViewsSql(?string $schema = null): string
    {
        return 'SELECT TABLE_NAME AS view_name, TABLE_SCHEMA AS table_schema, VIEW_DEFINITION AS view_definition, '
            . '0 AS materialized FROM INFORMATION_SCHEMA.VIEWS'
            . ($schema === null ? '' : ' WHERE TABLE_SCHEMA = ' . $this->quoteValue($schema))
            . ' ORDER BY TABLE_SCHEMA, TABLE_NAME';
    }

    #[\Override]
    public function getViewDefinitionSql(QualifiedName $view): string
    {
        return 'SELECT VIEW_DEFINITION AS definition FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = '
            . $this->quoteValue($this->schemaName($view)) . ' AND TABLE_NAME = '
            . $this->quoteValue($view->object->name);
    }

    #[\Override]
    public function getMaterializedViewsSql(?string $schema = null): string
    {
        throw $this->unsupported(Capability::MaterializedView);
    }

    #[\Override]
    public function getColumnsSql(QualifiedName $table): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '
            . $this->quoteValue($this->schemaName($table))
            . ' AND TABLE_NAME = ' . $this->quoteValue($table->object->name)
            . ' ORDER BY ORDINAL_POSITION';
    }

    #[\Override]
    public function getAllColumnsSql(string $database, ?string $schema = null): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '
            . $this->quoteValue($schema ?? $database)
            . ' ORDER BY TABLE_NAME, ORDINAL_POSITION';
    }

    #[\Override]
    public function getAllIndexesSql(string $database, ?string $schema = null): string
    {
        return "SELECT i.name AS index_name, i.is_unique AS non_unique, i.type_desc AS index_type, c.name AS column_name, ic.key_ordinal AS seq_in_index, ic.is_descending_key AS descending FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id WHERE SCHEMA_NAME(OBJECTPROPERTY(i.object_id, 'SchemaId')) = "
            . $this->quoteValue($schema ?? $database)
            . ' ORDER BY i.name, ic.key_ordinal';
    }

    #[\Override]
    public function getAllForeignKeysSql(string $database, ?string $schema = null): string
    {
        return 'SELECT fk.name AS constraint_name, OBJECT_SCHEMA_NAME(fkc.parent_object_id) AS table_schema, OBJECT_NAME(fkc.parent_object_id) AS table_name, COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS column_name, OBJECT_SCHEMA_NAME(fkc.referenced_object_id) AS referenced_table_schema, OBJECT_NAME(fkc.referenced_object_id) AS referenced_table_name, COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS referenced_column_name FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id WHERE OBJECT_SCHEMA_NAME(fkc.parent_object_id) = '
            . $this->quoteValue($schema ?? $database)
            . ' ORDER BY constraint_name';
    }

    #[\Override]
    public function getIndexesSql(QualifiedName $table): string
    {
        return 'SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(' . $this->quoteValue($this->quoteQualifiedName($table)) . ') ORDER BY name';
    }

    #[\Override]
    public function getForeignKeysSql(QualifiedName $table): string
    {
        return 'SELECT fk.name AS constraint_name, OBJECT_SCHEMA_NAME(fkc.parent_object_id) AS table_schema, OBJECT_NAME(fkc.parent_object_id) AS table_name, COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS column_name, OBJECT_SCHEMA_NAME(fkc.referenced_object_id) AS referenced_table_schema, OBJECT_NAME(fkc.referenced_object_id) AS referenced_table_name, COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS referenced_column_name FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id WHERE OBJECT_SCHEMA_NAME(fkc.parent_object_id) = '
            . $this->quoteValue($this->schemaName($table))
            . ' AND TABLE_NAME = ' . $this->quoteValue($table->object->name)
            . ' AND REFERENCED_TABLE_NAME IS NOT NULL';
    }

    #[\Override]
    public function getReferencingForeignKeysSql(QualifiedName $table): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '
            . $this->quoteValue($this->schemaName($table))
            . ' AND REFERENCED_TABLE_NAME = ' . $this->quoteValue($table->object->name)
            . ' AND REFERENCED_COLUMN_NAME IS NOT NULL';
    }

    #[\Override]
    public function getTriggersSql(QualifiedName $table): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ' . $this->quoteValue($this->schemaName($table)) . ' AND EVENT_OBJECT_TABLE = ' . $this->quoteValue($table->object->name);
    }

    #[\Override]
    public function getRoutineDetailSql(QualifiedName $routine): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = '
            . $this->quoteValue($this->schemaName($routine)) . ' AND ROUTINE_NAME = '
            . $this->quoteValue($routine->object->name);
    }

    #[\Override]
    public function getCheckConstraintsSql(QualifiedName $table): string
    {
        return 'SELECT CONSTRAINT_NAME AS constraint_name, CHECK_CLAUSE AS check_clause, 0 AS not_enforced '
            . 'FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '
            . $this->quoteValue($this->schemaName($table)) . ' AND TABLE_NAME = '
            . $this->quoteValue($table->object->name);
    }

    #[\Override]
    public function getUsersSql(): string
    {
        return 'SELECT name AS user_name, type_desc AS user_type FROM sys.database_principals WHERE authentication_type IS NOT NULL ORDER BY name';
    }

    #[\Override]
    public function getRoutinesSql(?string $schema = null): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.ROUTINES'
            . ($schema === null ? '' : ' WHERE ROUTINE_SCHEMA = ' . $this->quoteValue($schema));
    }

    #[\Override]
    public function getSequencesSql(?string $schema = null): string
    {
        return 'SELECT s.name AS sequence_name, SCHEMA_NAME(s.schema_id) AS sequence_schema, '
            . 's.start_value AS start_value, s.minimum_value AS minimum_value, s.maximum_value AS maximum_value, '
            . 's.increment AS increment, s.is_cycling AS cycle, s.cache_size AS cache '
            . 'FROM sys.sequences s'
            . ($schema === null ? '' : ' WHERE SCHEMA_NAME(s.schema_id) = ' . $this->quoteValue($schema))
            . ' ORDER BY sequence_schema, sequence_name';
    }

    #[\Override]
    public function getVariablesSql(): string
    {
        return 'SELECT name, value, value_in_use FROM sys.configurations ORDER BY name';
    }

    #[\Override]
    public function getStatusSql(): string
    {
        return 'SELECT name, value FROM sys.dm_os_performance_counters ORDER BY name';
    }

    #[\Override]
    public function getCharsetsSql(): string
    {
        return 'SELECT name AS charset_name FROM sys.fn_helpcollations() ORDER BY name';
    }

    #[\Override]
    public function getCollationsSql(?string $charset = null): string
    {
        return 'SELECT name AS collation_name, description FROM sys.fn_helpcollations() ORDER BY name';
    }

    #[\Override]
    public function getProcesslistSql(): string
    {
        return 'SELECT session_id, status, host_name, program_name, login_name FROM sys.dm_exec_sessions ORDER BY session_id';
    }

    private function databaseName(QualifiedName $table): string
    {
        if ($table->catalog instanceof Identifier) {
            return $table->catalog->name;
        }
        if ($table->schema instanceof Identifier) {
            return $table->schema->name;
        }

        return '';
    }

    private function schemaName(QualifiedName $name): string
    {
        return $name->schema instanceof Identifier ? $name->schema->name : 'dbo';
    }

    private function renderDataType(DataType $dataType): string
    {
        $type = strtoupper($dataType->name);
        if ($dataType->length !== null) {
            $type .= '(' . ($dataType->length < 0 ? 'MAX' : $dataType->length) . ')';
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
