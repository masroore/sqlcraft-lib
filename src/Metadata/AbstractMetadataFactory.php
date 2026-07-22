<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use InvalidArgumentException;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\DatabaseMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexColumnMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\DTO\PartitionInfo;
use SQLCraft\DTO\ProcessMeta;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\DTO\RoutineParameter;
use SQLCraft\DTO\SchemaMeta;
use SQLCraft\DTO\SequenceMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\DTO\TriggerMeta;
use SQLCraft\DTO\UserMeta;
use SQLCraft\DTO\ViewMeta;
use SQLCraft\ValueObjects\Collation;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\ForeignKeyAction;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\RoutineDirection;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

/** @internal */
abstract class AbstractMetadataFactory implements MetadataFactoryInterface
{
    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createColumnMeta(array $row): ColumnMeta
    {
        $row = $this->normalizeRow($row);
        $type = $this->dataType($row);
        $default = $this->defaultValue($this->value($row, 'column_default', 'dflt_value', 'default'));
        $extra = strtolower($this->stringValue($this->value($row, 'extra', 'generation_expression')) ?? '');

        return new ColumnMeta(
            name: $this->requiredString($row, 'column_name', 'name'),
            dataType: $type,
            nullable: $this->isNullable(
                $this->value($row, 'is_nullable', 'nullable', 'notnull')
                    ?? null,
                array_key_exists('notnull', $row),
            ),
            autoIncrement: $this->toBool($this->value($row, 'auto_increment', 'is_identity')) || str_contains($extra, 'auto_increment'),
            primary: $this->toBool($this->value($row, 'primary', 'pk')) || strtoupper($this->stringValue($this->value($row, 'column_key')) ?? '') === 'PRI',
            generated: $this->toBool($this->value($row, 'generated', 'is_generated')) || str_contains($extra, 'generated'),
            default: $default,
            collation: $this->collation($this->value($row, 'collation_name', 'collation')),
            comment: $this->stringValue($this->value($row, 'comment', 'column_comment')),
            onUpdate: $this->stringValue($this->value($row, 'on_update')),
            privileges: $this->integerList($this->value($row, 'privileges')),
            origName: $this->stringValue($this->value($row, 'orig_name')),
            defaultConstraintName: $this->stringValue($this->value($row, 'default_constraint_name')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createCheckConstraintMeta(array $row): CheckConstraintMeta
    {
        $row = $this->normalizeRow($row);

        return new CheckConstraintMeta(
            name: $this->requiredString($row, 'constraint_name', 'name'),
            expression: $this->requiredString($row, 'check_clause', 'expression', 'definition'),
            enforced: ! $this->toBool($this->value($row, 'not_enforced', 'is_not_enforced')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createDatabaseMeta(array $row): DatabaseMeta
    {
        $row = $this->normalizeRow($row);

        return new DatabaseMeta(
            name: $this->requiredString($row, 'database_name', 'schema_name', 'name'),
            charset: $this->stringValue($this->value($row, 'default_character_set_name', 'charset')),
            collation: $this->stringValue($this->value($row, 'default_collation_name', 'collation')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createProcessMeta(array $row): ProcessMeta
    {
        $row = $this->normalizeRow($row);

        return new ProcessMeta(
            id: (int) ($this->value($row, 'id', 'pid', 'process_id') ?? 0),
            user: $this->stringValue($this->value($row, 'user', 'usename')) ?? '',
            host: $this->stringValue($this->value($row, 'host', 'client_addr')),
            database: $this->stringValue($this->value($row, 'db', 'datname', 'database')),
            command: $this->stringValue($this->value($row, 'command', 'state')) ?? '',
            time: (int) ($this->value($row, 'time', 'query_start') ?? 0),
            state: $this->stringValue($this->value($row, 'state', 'wait_event')),
            query: $this->stringValue($this->value($row, 'info', 'query')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createTableStatus(array $row): TableStatus
    {
        $row = $this->normalizeRow($row);

        return new TableStatus(
            name: $this->requiredString($row, 'table_name', 'relname', 'name'),
            isView: $this->toBool($this->value($row, 'is_view')) || strtoupper($this->stringValue($this->value($row, 'table_type')) ?? '') === 'VIEW',
            engine: $this->stringValue($this->value($row, 'engine')),
            comment: $this->stringValue($this->value($row, 'comment', 'table_comment')),
            oid: $this->nullableInt($this->value($row, 'oid')),
            rows: $this->nullableInt($this->value($row, 'rows', 'table_rows', 'reltuples')),
            collation: $this->stringValue($this->value($row, 'collation', 'table_collation')),
            autoIncrement: $this->nullableInt($this->value($row, 'auto_increment')),
            dataLength: $this->nullableInt($this->value($row, 'data_length')),
            indexLength: $this->nullableInt($this->value($row, 'index_length')),
            dataFree: $this->nullableInt($this->value($row, 'data_free')),
            createOptions: $this->stringValue($this->value($row, 'create_options')),
            partitioned: $this->toBool($this->value($row, 'partitioned', 'is_partitioned')),
            schema: $this->stringValue($this->value($row, 'table_schema', 'schema')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createSchemaMeta(array $row): SchemaMeta
    {
        $row = $this->normalizeRow($row);

        return new SchemaMeta(
            name: $this->requiredString($row, 'schema_name', 'name'),
            catalog: $this->stringValue($this->value($row, 'catalog_name', 'catalog', 'table_catalog')),
            owner: $this->stringValue($this->value($row, 'schema_owner', 'owner')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createSequenceMeta(array $row): SequenceMeta
    {
        $row = $this->normalizeRow($row);

        return new SequenceMeta(
            name: $this->requiredString($row, 'sequence_name', 'name'),
            schema: $this->stringValue($this->value($row, 'sequence_schema', 'schema')),
            startValue: $this->integerOrString($this->value($row, 'start_value', 'start')),
            minValue: $this->integerOrString($this->value($row, 'minimum_value', 'min_value', 'min')),
            maxValue: $this->integerOrString($this->value($row, 'maximum_value', 'max_value', 'max')),
            increment: (int) ($this->value($row, 'increment', 'increment_by') ?? 1),
            cycle: $this->toBool($this->value($row, 'cycle', 'is_cycled')),
            ownedByTable: $this->stringValue($this->value($row, 'owned_by_table')),
            ownedByColumn: $this->stringValue($this->value($row, 'owned_by_column')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createDataType(array $row): DataType
    {
        return $this->dataType($this->normalizeRow($row));
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createPartitionInfo(array $row): PartitionInfo
    {
        $row = $this->normalizeRow($row);

        return new PartitionInfo(
            name: $this->requiredString($row, 'name', 'partition_name'),
            schema: $this->stringValue($this->value($row, 'schema', 'table_schema', 'partition_schema')),
            method: $this->requiredString($row, 'method', 'partition_method'),
            expression: $this->stringValue($this->value($row, 'expression', 'partition_expression')),
            parentTable: $this->stringValue($this->value($row, 'parent_table', 'table_name')),
            bound: $this->stringValue($this->value($row, 'bound', 'partition_description')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createIndexMeta(array $row): IndexMeta
    {
        $row = $this->normalizeRow($row);
        $columnName = $this->stringValue($this->value($row, 'column_name', 'column'));
        $expression = $this->stringValue($this->value($row, 'expression'));
        $columns = $this->indexColumns($this->value($row, 'columns'), $columnName, $expression, $row);

        return new IndexMeta(
            name: $this->requiredString($row, 'index_name', 'key_name', 'name'),
            type: $this->indexType($this->value($row, 'index_type', 'type', 'index_kind')),
            columns: $columns,
            unique: ! $this->toBool($this->value($row, 'non_unique')) && ($this->toBool($this->value($row, 'unique', 'is_unique', 'indisunique')) || strtoupper($this->stringValue($this->value($row, 'index_name', 'key_name')) ?? '') === 'PRIMARY'),
            comment: $this->stringValue($this->value($row, 'comment', 'index_comment')),
            algorithm: $this->stringValue($this->value($row, 'algorithm')),
            filterExpression: $this->stringValue($this->value($row, 'filter_expression', 'predicate')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createForeignKeyMeta(array $row): ForeignKeyMeta
    {
        $row = $this->normalizeRow($row);

        return new ForeignKeyMeta(
            constraintName: $this->requiredString($row, 'constraint_name', 'name', 'id'),
            targetDatabase: $this->stringValue($this->value($row, 'target_database', 'referenced_table_catalog')),
            targetSchema: $this->stringValue($this->value($row, 'target_schema', 'referenced_table_schema')),
            targetTable: $this->requiredString($row, 'target_table', 'referenced_table_name', 'table'),
            sourceColumns: $this->stringList($this->value($row, 'source_columns', 'source_column', 'column_name', 'from')),
            targetColumns: $this->stringList($this->value($row, 'target_columns', 'target_column', 'referenced_column_name', 'to')),
            onDelete: $this->foreignKeyAction($this->value($row, 'on_delete', 'delete_rule')),
            onUpdate: $this->foreignKeyAction($this->value($row, 'on_update', 'update_rule')),
            definition: $this->stringValue($this->value($row, 'definition', 'constraint_definition')),
            deferrable: $this->toBool($this->value($row, 'deferrable', 'is_deferrable')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createTriggerMeta(array $row): TriggerMeta
    {
        $row = $this->normalizeRow($row);

        return new TriggerMeta(
            name: $this->requiredString($row, 'trigger_name', 'name'),
            timing: $this->triggerTiming($this->value($row, 'timing', 'action_timing')),
            event: $this->triggerEvent($this->value($row, 'event', 'event_manipulation')),
            body: $this->requiredString($row, 'body', 'action_statement', 'sql'),
            definer: $this->stringValue($this->value($row, 'definer')),
            table: $this->stringValue($this->value($row, 'table_name', 'event_object_table')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createUserMeta(array $row): UserMeta
    {
        $row = $this->normalizeRow($row);

        return new UserMeta(
            name: $this->requiredString($row, 'user', 'rolname', 'name'),
            host: $this->stringValue($this->value($row, 'host', 'host_name')),
            plugin: $this->stringValue($this->value($row, 'plugin', 'auth_method')),
            superuser: $this->toBool($this->value($row, 'super_priv', 'rolsuper', 'superuser')),
            canLogin: array_key_exists('rolcanlogin', $row)
                ? $this->toBool($row['rolcanlogin'])
                : ! $this->toBool($this->value($row, 'account_locked')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createViewMeta(array $row): ViewMeta
    {
        $row = $this->normalizeRow($row);

        return new ViewMeta(
            name: $this->requiredString($row, 'table_name', 'view_name', 'name'),
            schema: $this->stringValue($this->value($row, 'table_schema', 'schema')),
            definition: $this->stringValue($this->value($row, 'view_definition', 'definition', 'sql')),
            materialized: $this->toBool($this->value($row, 'materialized', 'is_materialized')),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    #[\Override]
    public function createRoutineMeta(array $row): RoutineMeta
    {
        $row = $this->normalizeRow($row);
        $returnType = $this->stringValue($this->value($row, 'return_type'));

        return new RoutineMeta(
            name: $this->requiredString($row, 'routine_name', 'name'),
            type: strtoupper($this->stringValue($this->value($row, 'routine_type', 'type')) ?? 'FUNCTION'),
            params: $this->routineParameters($this->value($row, 'params', 'parameters')),
            returnType: $returnType === null ? null : new DataType($returnType),
            body: $this->stringValue($this->value($row, 'body', 'routine_definition', 'definition')) ?? '',
            language: $this->stringValue($this->value($row, 'language', 'routine_language')),
            comment: $this->stringValue($this->value($row, 'comment', 'routine_comment')),
            definer: $this->stringValue($this->value($row, 'definer', 'routine_schema')) ?? '',
            deterministic: $this->toBool($this->value($row, 'deterministic', 'is_deterministic')),
            sqlDataAccess: $this->stringValue($this->value($row, 'sql_data_access', 'data_access')) ?? '',
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    private function dataType(array $row): DataType
    {
        $rawName = $this->requiredString($row, 'data_type', 'udt_name', 'column_type', 'type');
        $name = strtoupper(trim((string) preg_replace('/\s*\([^)]*\)/', '', $rawName)));
        $length = $this->nullableInt($this->value($row, 'character_maximum_length', 'length'));
        $precision = $this->nullableInt($this->value($row, 'numeric_precision', 'precision'));
        $scale = $this->nullableInt($this->value($row, 'numeric_scale', 'scale'));
        $columnType = $this->stringValue($this->value($row, 'column_type'));
        $unsigned = $this->toBool($this->value($row, 'unsigned'))
            || str_contains(strtolower($rawName), 'unsigned')
            || str_contains(strtolower($columnType ?? ''), 'unsigned');

        if ($length === null && preg_match('/\((\d+)\)/', $rawName, $matches) === 1) {
            $length = (int) $matches[1];
        }

        return new DataType(
            name: $name,
            length: $length,
            precision: $precision,
            scale: $scale,
            unsigned: $unsigned,
            collation: $this->stringValue($this->value($row, 'collation_name', 'collation')),
            charset: $this->stringValue($this->value($row, 'character_set_name', 'charset')),
        );
    }

    private function defaultValue(bool|float|int|string|null $value): DefaultValue
    {
        if ($value === null) {
            return DefaultValue::nullValue();
        }

        $value = (string) $value;
        if ($value === '') {
            return DefaultValue::emptyString();
        }
        if (preg_match('/^nextval\s*\(/i', $value) === 1) {
            return DefaultValue::sequenceNext($value);
        }
        if (preg_match('/^(?:current_|localtimestamp|now\s*\(|uuid\s*\(|gen_)/i', $value) === 1 || str_starts_with($value, '(')) {
            return DefaultValue::expression($value);
        }

        return DefaultValue::literal($value);
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $row
     * @return list<IndexColumnMeta>
     */
    private function indexColumns(bool|float|int|string|null $raw, ?string $columnName, ?string $expression, array $row): array
    {
        $names = $this->stringList($raw);
        if ($names === [] && $columnName !== null) {
            $names = [$columnName];
        }

        $descending = strtoupper($this->stringValue($this->value($row, 'collation', 'sort_order')) ?? '') === 'D'
            || $this->toBool($this->value($row, 'descending'));
        $length = $this->nullableInt($this->value($row, 'sub_part', 'length'));

        return array_map(
            fn (string $name): IndexColumnMeta => new IndexColumnMeta($name, $descending, $length, $expression),
            $names,
        );
    }

    private function indexType(bool|float|int|string|null $value): IndexType
    {
        $value = strtoupper((string) ($value ?? 'INDEX'));

        return match ($value) {
            'PRIMARY', 'PRIMARY KEY' => IndexType::PRIMARY,
            'UNIQUE', 'UNIQUE INDEX' => IndexType::UNIQUE,
            'FULLTEXT' => IndexType::FULLTEXT,
            'SPATIAL' => IndexType::SPATIAL,
            'GIN' => IndexType::GIN,
            'GIST' => IndexType::GIST,
            'BRIN' => IndexType::BRIN,
            default => IndexType::INDEX,
        };
    }

    private function foreignKeyAction(bool|float|int|string|null $value): ForeignKeyAction
    {
        return match (strtoupper((string) ($value ?? 'NO ACTION'))) {
            'RESTRICT' => ForeignKeyAction::RESTRICT,
            'CASCADE' => ForeignKeyAction::CASCADE,
            'SET NULL' => ForeignKeyAction::SET_NULL,
            'SET DEFAULT' => ForeignKeyAction::SET_DEFAULT,
            default => ForeignKeyAction::NO_ACTION,
        };
    }

    private function triggerTiming(bool|float|int|string|null $value): TriggerTiming
    {
        return match (strtoupper(str_replace('_', ' ', (string) ($value ?? 'BEFORE')))) {
            'AFTER' => TriggerTiming::AFTER,
            'INSTEAD OF' => TriggerTiming::INSTEAD_OF,
            default => TriggerTiming::BEFORE,
        };
    }

    private function triggerEvent(bool|float|int|string|null $value): TriggerEvent
    {
        return match (strtoupper((string) ($value ?? 'INSERT'))) {
            'UPDATE' => TriggerEvent::UPDATE,
            'DELETE' => TriggerEvent::DELETE,
            'TRUNCATE' => TriggerEvent::TRUNCATE,
            default => TriggerEvent::INSERT,
        };
    }

    /** @return list<RoutineParameter> */
    private function routineParameters(bool|float|int|string|null $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        $parameters = [];
        foreach (explode(';', (string) $value) as $rawParameter) {
            $parts = array_map('trim', explode(':', $rawParameter));
            if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }

            $direction = match (strtoupper($parts[2])) {
                'OUT' => RoutineDirection::OUT,
                'INOUT' => RoutineDirection::INOUT,
                default => RoutineDirection::IN,
            };
            $parameters[] = new RoutineParameter($parts[0], new DataType($parts[1]), $direction);
        }

        return $parameters;
    }

    private function isNullable(bool|float|int|string|null $value, bool $notNullKeyPresent): bool
    {
        if ($value === null) {
            return true;
        }
        if ($notNullKeyPresent) {
            return ! $this->toBool($value);
        }
        if (is_string($value)) {
            return ! in_array(strtoupper($value), ['NO', 'N', '0', 'FALSE'], true);
        }

        return $this->toBool($value);
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $row
     * @return array<string, bool|float|int|string|null>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[strtolower(str_replace([' ', '-'], '_', $key))] = $value;
        }

        return $normalized;
    }

    /** @param array<string, bool|float|int|string|null> $row */
    private function value(array $row, string ...$keys): bool|float|int|string|null
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    /** @param array<string, bool|float|int|string|null> $row */
    private function requiredString(array $row, string ...$keys): string
    {
        $value = $this->stringValue($this->value($row, ...$keys));
        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('Metadata row is missing required field: %s.', $keys[0]));
        }

        return $value;
    }

    private function stringValue(bool|float|int|string|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function nullableInt(bool|float|int|string|null $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function integerOrString(bool|float|int|string|null $value): int|string
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return (string) $value;
    }

    private function toBool(bool|float|int|string|null $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtoupper($value), ['1', 'Y', 'YES', 'TRUE', 'ON'], true);
        }

        return ! in_array($value, [null, 0.0, 0], true);
    }

    /** @return list<string> */
    private function stringList(bool|float|int|string|null $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $item): bool => $item !== ''));
    }

    /** @return list<int> */
    private function integerList(bool|float|int|string|null $value): array
    {
        return array_map('intval', $this->stringList($value));
    }

    private function collation(bool|float|int|string|null $value): ?Collation
    {
        $value = $this->stringValue($value);
        if ($value === null || $value === '') {
            return null;
        }

        return new Collation($value);
    }
}
