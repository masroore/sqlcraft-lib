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
    public function getSchemasSql(): string
    {
        return 'SELECT schema_name AS name, catalog_name AS catalog, schema_owner AS owner '
            . 'FROM information_schema.schemata ORDER BY schema_name';
    }

    #[\Override]
    public function getTypesSql(?string $schema = null): string
    {
        $schemaFilter = $schema === null ? '' : ' AND namespace.nspname = ' . $this->quoteValue($schema);

        return "SELECT type.typname AS data_type, namespace.nspname AS schema FROM pg_type type"
            . ' JOIN pg_namespace namespace ON namespace.oid = type.typnamespace '
            . "WHERE type.typtype IN ('b', 'c', 'd', 'e')" . $schemaFilter
            . ' ORDER BY namespace.nspname, type.typname';
    }

    #[\Override]
    public function getTablesSql(string $database, ?string $schema = null): string
    {
        return $this->tableStatusSql($schema, null);
    }

    #[\Override]
    public function getTableStatusSql(QualifiedName $table): string
    {
        return $this->tableStatusSql(
            $table->schema?->name,
            $table->object->name,
        );
    }

    #[\Override]
    public function getParentTablesSql(QualifiedName $table): string
    {
        $schema = $table->schema?->name;
        return 'SELECT parent.relname AS table_name, parent_ns.nspname AS schema, '
            . 'current_database() AS catalog FROM pg_inherits inheritance '
            . 'JOIN pg_class child ON child.oid = inheritance.inhrelid '
            . 'JOIN pg_class parent ON parent.oid = inheritance.inhparent '
            . 'JOIN pg_namespace parent_ns ON parent_ns.oid = parent.relnamespace '
            . 'JOIN pg_namespace child_ns ON child_ns.oid = child.relnamespace '
            . 'WHERE child.relname = ' . $this->quoteValue($table->object->name)
            . ($schema === null ? '' : ' AND child_ns.nspname = ' . $this->quoteValue($schema))
            . ' ORDER BY parent.relname';
    }

    #[\Override]
    public function getPartitionsSql(QualifiedName $table): string
    {
        $schema = $table->schema?->name;
        $schemaFilter = $schema === null ? '' : ' AND parent_ns.nspname = ' . $this->quoteValue($schema);

        return "SELECT child.relname AS name, child_ns.nspname AS schema,
"
            . "CASE parent_part.partstrat WHEN 'r' THEN 'RANGE' WHEN 'l' THEN 'LIST' WHEN 'h' THEN 'HASH' ELSE parent_part.partstrat END AS method,
"
            . 'pg_get_partkeydef(parent.oid) AS expression, parent.relname AS parent_table, '
            . 'pg_get_expr(child.relpartbound, child.oid) AS bound FROM pg_inherits inheritance '
            . 'JOIN pg_class child ON child.oid = inheritance.inhrelid '
            . 'JOIN pg_class parent ON parent.oid = inheritance.inhparent '
            . 'JOIN pg_namespace child_ns ON child_ns.oid = child.relnamespace '
            . 'JOIN pg_namespace parent_ns ON parent_ns.oid = parent.relnamespace '
            . 'JOIN pg_partitioned_table parent_part ON parent_part.partrelid = parent.oid '
            . 'WHERE parent.relname = ' . $this->quoteValue($table->object->name) . $schemaFilter
            . ' ORDER BY child.relname';
    }

    #[\Override]
    public function getViewsSql(?string $schema = null): string
    {
        return 'SELECT viewname AS view_name, schemaname AS table_schema, definition AS view_definition, '
            . '0 AS materialized FROM pg_views'
            . ($schema === null ? '' : ' WHERE schemaname = ' . $this->quoteValue($schema))
            . ' ORDER BY schemaname, viewname';
    }

    #[\Override]
    public function getViewDefinitionSql(QualifiedName $view): string
    {
        return 'SELECT definition FROM pg_views WHERE schemaname = '
            . $this->quoteValue($view->schema instanceof Identifier ? $view->schema->name : 'public') . ' AND viewname = '
            . $this->quoteValue($view->object->name);
    }

    #[\Override]
    public function getMaterializedViewsSql(?string $schema = null): string
    {
        return 'SELECT matviewname AS view_name, schemaname AS table_schema, definition AS view_definition, '
            . '1 AS materialized FROM pg_matviews'
            . ($schema === null ? '' : ' WHERE schemaname = ' . $this->quoteValue($schema))
            . ' ORDER BY schemaname, matviewname';
    }

    #[\Override]
    public function getColumnsSql(QualifiedName $table): string
    {
        return 'SELECT * FROM information_schema.columns WHERE table_name = ' . $this->quoteValue($table->object->name)
            . ($table->schema instanceof Identifier ? ' AND table_schema = ' . $this->quoteValue($table->schema->name) : '')
            . ' ORDER BY ordinal_position';
    }

    #[\Override]
    public function getAllColumnsSql(string $database, ?string $schema = null): string
    {
        return 'SELECT * FROM information_schema.columns WHERE table_catalog = '
            . $this->quoteValue($database)
            . ($schema === null ? '' : ' AND table_schema = ' . $this->quoteValue($schema))
            . ' ORDER BY table_schema, table_name, ordinal_position';
    }

    #[\Override]
    public function getAllIndexesSql(string $database, ?string $schema = null): string
    {
        return "SELECT namespace.nspname AS table_schema, table.relname AS table_name, index_rel.relname AS index_name, "
            . "CASE WHEN index_data.indisprimary THEN 'PRIMARY' WHEN index_data.indisunique THEN 'UNIQUE' ELSE 'INDEX' END AS index_type, "
            . 'index_data.indisunique AS is_unique, access_method.amname AS algorithm, attribute.attname AS column_name '
            . 'FROM pg_index index_data JOIN pg_class table ON table.oid = index_data.indrelid '
            . 'JOIN pg_class index_rel ON index_rel.oid = index_data.indexrelid '
            . 'JOIN pg_namespace namespace ON namespace.oid = table.relnamespace '
            . 'JOIN pg_am access_method ON access_method.oid = index_rel.relam '
            . 'LEFT JOIN LATERAL unnest(index_data.indkey) WITH ORDINALITY key_columns(attnum, position) ON true '
            . 'LEFT JOIN pg_attribute attribute ON attribute.attrelid = table.oid AND attribute.attnum = key_columns.attnum '
            . "WHERE table.relnamespace NOT IN (SELECT oid FROM pg_namespace WHERE nspname LIKE 'pg_%' OR nspname = 'information_schema')"
            . ($schema === null ? '' : ' AND namespace.nspname = ' . $this->quoteValue($schema))
            . ' ORDER BY namespace.nspname, table.relname, index_rel.relname, key_columns.position';
    }

    #[\Override]
    public function getAllForeignKeysSql(string $database, ?string $schema = null): string
    {
        return 'SELECT tc.constraint_name, tc.table_schema, tc.table_name, kcu.column_name AS source_column, '
            . 'ccu.table_schema AS target_schema, ccu.table_name AS target_table, ccu.column_name AS target_column '
            . 'FROM information_schema.table_constraints tc '
            . 'JOIN information_schema.key_column_usage kcu ON kcu.constraint_catalog = tc.constraint_catalog '
            . 'AND kcu.constraint_schema = tc.constraint_schema AND kcu.constraint_name = tc.constraint_name '
            . 'JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_catalog = tc.constraint_catalog '
            . 'AND ccu.constraint_schema = tc.constraint_schema AND ccu.constraint_name = tc.constraint_name '
            . 'WHERE tc.constraint_catalog = ' . $this->quoteValue($database)
            . " AND tc.constraint_type = 'FOREIGN KEY'"
            . ($schema === null ? '' : ' AND tc.table_schema = ' . $this->quoteValue($schema))
            . ' ORDER BY tc.table_schema, tc.table_name, tc.constraint_name, kcu.ordinal_position';
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
    public function getRoutineDetailSql(QualifiedName $routine): string
    {
        return 'SELECT * FROM information_schema.routines WHERE routine_schema = '
            . $this->quoteValue(($routine->schema instanceof Identifier ? $routine->schema->name : 'public')) . ' AND routine_name = '
            . $this->quoteValue($routine->object->name);
    }

    #[\Override]
    public function getCheckConstraintsSql(QualifiedName $table): string
    {
        return 'SELECT tc.constraint_name, cc.check_clause, 0 AS not_enforced '
            . 'FROM information_schema.table_constraints tc JOIN information_schema.check_constraints cc '
            . 'ON cc.constraint_name = tc.constraint_name AND cc.constraint_schema = tc.constraint_schema '
            . 'WHERE tc.table_schema = ' . $this->quoteValue(($table->schema instanceof Identifier ? $table->schema->name : 'public'))
            . ' AND tc.table_name = ' . $this->quoteValue($table->object->name)
            . " AND tc.constraint_type = 'CHECK'";
    }

    #[\Override]
    public function getUsersSql(): string
    {
        return 'SELECT rolname, rolsuper, rolcanlogin FROM pg_roles ORDER BY rolname';
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
    public function getStatusSql(): string
    {
        return 'SELECT name, setting AS value FROM pg_settings ORDER BY name';
    }

    #[\Override]
    public function getCharsetsSql(): string
    {
        throw CapabilityNotSupportedException::for(Capability::Charset, 'pgsql');
    }

    #[\Override]
    public function getCollationsSql(?string $charset = null): string
    {
        return 'SELECT collname AS name, pg_encoding_to_char(collencoding) AS charset '
            . 'FROM pg_collation ORDER BY collname';
    }

    #[\Override]
    public function getProcesslistSql(): string
    {
        return 'SELECT pid, usename, datname, state, query FROM pg_stat_activity';
    }

    private function tableStatusSql(?string $schema, ?string $table): string
    {
        $sql = "SELECT c.relname AS table_name, CASE WHEN c.relkind IN ('v', 'm') THEN 'VIEW' ELSE 'BASE TABLE' END AS table_type,
"
            . 'n.nspname AS schema, c.oid, c.reltuples AS rows, '
            . "CASE WHEN c.relkind IN ('v', 'm') THEN 1 ELSE 0 END AS is_view,
"
            . 'CASE WHEN p.partrelid IS NOT NULL THEN 1 ELSE 0 END AS partitioned '
            . 'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'LEFT JOIN pg_partitioned_table p ON p.partrelid = c.oid '
            . "WHERE c.relkind IN ('r', 'p', 'v', 'm') AND n.nspname NOT LIKE 'pg_%' AND n.nspname <> 'information_schema'";

        if ($schema !== null) {
            $sql .= ' AND n.nspname = ' . $this->quoteValue($schema);
        }
        if ($table !== null) {
            $sql .= ' AND c.relname = ' . $this->quoteValue($table);
        }

        return $sql . ' ORDER BY n.nspname, c.relname';
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
