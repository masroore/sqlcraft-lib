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

/** MySQL dialect; designed for MariaDbPlatform specialization. */
class MySQLPlatform extends AbstractPlatform
{
    #[\Override]
    public function getName(): string
    {
        return 'mysql';
    }

    #[\Override]
    public function getFlavor(): ?string
    {
        return null;
    }

    #[\Override]
    public function getServerVersion(ConnectionInterface $connection): ServerVersion
    {
        $values = $connection->query('SELECT VERSION()')->fetchColumn();
        if (isset($values[0]) && is_string($values[0])) {
            return new ServerVersion($values[0]);
        }

        return new ServerVersion('5.7.0');
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
                Capability::Routine,
                Capability::Procedure,
                Capability::Event,
                Capability::Copy,
                Capability::MoveColumn,
                Capability::InsertUpdate,
                Capability::Compression,
                Capability::Partitions,
            ],
            'versioned' => [
                [Capability::GeneratedColumns, [5, 7, 0]],
                [Capability::DescendingIndexes, [8, 0, 0]],
                [Capability::CheckConstraints, [8, 0, 16]],
            ],
        ];
    }

    #[\Override]
    public function getDefaultCharset(): ?string
    {
        return 'utf8mb4';
    }

    #[\Override]
    public function getDefaultCollation(): ?string
    {
        return 'utf8mb4_unicode_ci';
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
        return ['SELECT', 'FROM', 'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'OFFSET', 'SHOW', 'CREATE', 'ALTER', 'DROP'];
    }

    #[\Override]
    public function quoteIdentifier(Identifier $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier->name) . '`';
    }

    #[\Override]
    public function quoteValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'",
            default => throw new InvalidArgumentException('MySQL values must be scalar or null.'),
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
            'int' => 'BIGINT',
            'float' => 'DOUBLE',
            'bool' => 'TINYINT(1)',
            'string' => 'VARCHAR(255)',
            'null' => 'TEXT',
            default => 'BLOB',
        };
    }

    /** @return list<string> */
    #[\Override]
    public function getSupportedTypes(): array
    {
        return [
            'TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'INTEGER', 'BIGINT',
            'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE', 'REAL',
            'CHAR', 'VARCHAR', 'TEXT', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT',
            'BINARY', 'VARBINARY', 'BLOB', 'TINYBLOB', 'MEDIUMBLOB', 'LONGBLOB',
            'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR', 'JSON', 'ENUM', 'SET',
        ];
    }

    /** @return list<string> */
    #[\Override]
    public function getUnsignedTypes(): array
    {
        return ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'INTEGER', 'BIGINT', 'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE', 'REAL'];
    }

    /** @return list<string> */
    #[\Override]
    public function getCollatableTypes(): array
    {
        return ['CHAR', 'VARCHAR', 'TEXT', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT', 'ENUM', 'SET'];
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
            $definition .= ' AUTO_INCREMENT';
        }
        if ($column->comment !== null) {
            $definition .= ' COMMENT ' . $this->quoteValue($column->comment);
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
        $sql = 'CREATE TABLE ' . $this->quoteQualifiedName($table) . ' (' . implode(', ', $clauses) . ')';
        if (isset($tableOptions['engine']) && is_string($tableOptions['engine'])) {
            $sql .= ' ENGINE=' . $tableOptions['engine'];
        }
        if (isset($tableOptions['charset']) && is_string($tableOptions['charset'])) {
            $sql .= ' DEFAULT CHARSET=' . $tableOptions['charset'];
        }
        if (isset($tableOptions['collation']) && is_string($tableOptions['collation'])) {
            $sql .= ' COLLATE=' . $tableOptions['collation'];
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
        $prefix = match ($index->type->value) {
            'FULLTEXT' => 'FULLTEXT ',
            'SPATIAL' => 'SPATIAL ',
            default => $index->unique ? 'UNIQUE ' : '',
        };

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
        return 'SHOW DATABASES';
    }

    #[\Override]
    public function getSchemasSql(): string
    {
        return '';
    }

    #[\Override]
    public function getTypesSql(?string $schema = null): string
    {
        throw CapabilityNotSupportedException::for(Capability::Type, 'mysql');
    }

    #[\Override]
    public function getTablesSql(string $database, ?string $schema = null): string
    {
        return $this->tableStatusSql($database, null);
    }

    #[\Override]
    public function getTableStatusSql(QualifiedName $table): string
    {
        return $this->tableStatusSql($this->databaseName($table), $table->object->name);
    }

    #[\Override]
    public function getParentTablesSql(QualifiedName $table): string
    {
        return '';
    }

    #[\Override]
    public function getPartitionsSql(QualifiedName $table): string
    {
        $database = $this->databaseName($table);

        return 'SELECT PARTITION_NAME AS name, TABLE_SCHEMA AS schema, PARTITION_METHOD AS method, '
            . 'PARTITION_EXPRESSION AS expression, TABLE_NAME AS parent_table, '
            . 'PARTITION_DESCRIPTION AS bound FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA = '
            . $this->quoteValue($database) . ' AND TABLE_NAME = ' . $this->quoteValue($table->object->name)
            . ' AND PARTITION_NAME IS NOT NULL ORDER BY PARTITION_ORDINAL_POSITION';
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
            . $this->quoteValue($this->databaseName($view)) . ' AND TABLE_NAME = '
            . $this->quoteValue($view->object->name);
    }

    #[\Override]
    public function getMaterializedViewsSql(?string $schema = null): string
    {
        throw CapabilityNotSupportedException::for(Capability::MaterializedView, 'mysql');
    }

    #[\Override]
    public function getColumnsSql(QualifiedName $table): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '
            . $this->quoteValue($this->databaseName($table))
            . ' AND TABLE_NAME = ' . $this->quoteValue($table->object->name)
            . ' ORDER BY ORDINAL_POSITION';
    }

    #[\Override]
    public function getIndexesSql(QualifiedName $table): string
    {
        return 'SHOW INDEX FROM ' . $this->quoteQualifiedName($table);
    }

    #[\Override]
    public function getForeignKeysSql(QualifiedName $table): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '
            . $this->quoteValue($this->databaseName($table))
            . ' AND TABLE_NAME = ' . $this->quoteValue($table->object->name)
            . ' AND REFERENCED_TABLE_NAME IS NOT NULL';
    }

    #[\Override]
    public function getReferencingForeignKeysSql(QualifiedName $table): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '
            . $this->quoteValue($this->databaseName($table))
            . ' AND REFERENCED_TABLE_NAME = ' . $this->quoteValue($table->object->name)
            . ' AND REFERENCED_COLUMN_NAME IS NOT NULL';
    }

    #[\Override]
    public function getTriggersSql(QualifiedName $table): string
    {
        return 'SHOW TRIGGERS FROM ' . $this->quoteIdentifier($table->catalog ?? $table->schema ?? $table->object);
    }

    #[\Override]
    public function getRoutineDetailSql(QualifiedName $routine): string
    {
        return 'SELECT * FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = '
            . $this->quoteValue($this->databaseName($routine)) . ' AND ROUTINE_NAME = '
            . $this->quoteValue($routine->object->name);
    }

    #[\Override]
    public function getCheckConstraintsSql(QualifiedName $table): string
    {
        return 'SELECT CONSTRAINT_NAME AS constraint_name, CHECK_CLAUSE AS check_clause, 0 AS not_enforced '
            . 'FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '
            . $this->quoteValue($this->databaseName($table)) . ' AND TABLE_NAME = '
            . $this->quoteValue($table->object->name);
    }

    #[\Override]
    public function getUsersSql(): string
    {
        return 'SELECT User AS user, Host AS host, plugin, Super_priv AS super_priv, account_locked FROM mysql.user';
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
        throw CapabilityNotSupportedException::for(Capability::Sequence, 'mysql');
    }

    #[\Override]
    public function getVariablesSql(): string
    {
        return 'SHOW VARIABLES';
    }

    #[\Override]
    public function getStatusSql(): string
    {
        return 'SHOW STATUS';
    }

    #[\Override]
    public function getCharsetsSql(): string
    {
        return 'SHOW CHARACTER SET';
    }

    #[\Override]
    public function getCollationsSql(?string $charset = null): string
    {
        return 'SHOW COLLATION' . ($charset === null ? '' : ' WHERE Charset = ' . $this->quoteValue($charset));
    }

    #[\Override]
    public function getProcesslistSql(): string
    {
        return 'SHOW PROCESSLIST';
    }

    private function tableStatusSql(string $database, ?string $table): string
    {
        $sql = 'SELECT TABLE_NAME AS table_name, TABLE_TYPE AS table_type, TABLE_SCHEMA AS table_schema, '
            . 'ENGINE AS engine, TABLE_COMMENT AS table_comment, TABLE_ROWS AS table_rows, '
            . 'TABLE_COLLATION AS table_collation, AUTO_INCREMENT AS auto_increment, '
            . 'DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, DATA_FREE AS data_free, '
            . 'CREATE_OPTIONS AS create_options, '
            . "CASE WHEN CREATE_OPTIONS LIKE '%partitioned%' THEN 1 ELSE 0 END AS partitioned "
            . 'FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ' . $this->quoteValue($database);

        if ($table !== null) {
            $sql .= ' AND TABLE_NAME = ' . $this->quoteValue($table);
        }

        return $sql . ' ORDER BY TABLE_NAME';
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

    private function renderDataType(DataType $dataType): string
    {
        $type = strtoupper($dataType->name);
        if ($dataType->length !== null) {
            $type .= '(' . $dataType->length . ')';
        } elseif ($dataType->precision !== null) {
            $type .= '(' . $dataType->precision . ($dataType->scale === null ? '' : ', ' . $dataType->scale) . ')';
        }
        if ($dataType->unsigned) {
            $type .= ' UNSIGNED';
        }
        if ($dataType->charset !== null) {
            $type .= ' CHARACTER SET ' . $dataType->charset;
        }
        if ($dataType->collation !== null) {
            $type .= ' COLLATE ' . $dataType->collation;
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
