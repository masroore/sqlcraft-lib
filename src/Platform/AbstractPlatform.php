<?php

declare(strict_types=1);

namespace SQLCraft\Platform;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\DDL\AlterTableDefinitionInterface;
use SQLCraft\Contracts\DDL\CheckConstraintDefinitionInterface;
use SQLCraft\Contracts\DDL\ColumnDefinitionInterface;
use SQLCraft\Contracts\DDL\ForeignKeyDefinitionInterface;
use SQLCraft\Contracts\DDL\IndexDefinitionInterface;
use SQLCraft\Contracts\DDL\RoutineParameterDefinitionInterface;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexColumnMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Capabilities\ExtendedCapability;
use SQLCraft\Capabilities\PlatformCapabilityResolver;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\ServerVersion;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

abstract class AbstractPlatform implements PlatformInterface
{
    #[\Override]
    public function quoteIdentifier(Identifier $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier->name) . '"';
    }

    #[\Override]
    public function renderDropTableStatement(QualifiedName $table, bool $ifExists, bool $cascade): string
    {
        $qualified = $this->quoteQualifiedName($table);
        $sql = 'DROP TABLE' . ($ifExists ? ' IF EXISTS' : '') . ' ' . $qualified;

        return $cascade ? $sql . ' CASCADE' : $sql;
    }

    /**
     * @param list<Identifier> $columns
     */
    #[\Override]
    public function renderCreateViewStatement(QualifiedName $name, string $selectSql, bool $orReplace, array $columns, ?string $checkOption): string
    {
        $columnList = $columns === [] ? '' : ' (' . implode(', ', array_map($this->quoteIdentifier(...), $columns)) . ')';
        $sql = 'CREATE ' . ($orReplace ? 'OR REPLACE ' : '') . 'VIEW ' . $this->quoteQualifiedName($name) . $columnList . ' AS ' . $selectSql;

        return $checkOption === null ? $sql : $sql . ' WITH ' . $checkOption . ' CHECK OPTION';
    }

    #[\Override]
    public function renderDropViewStatement(QualifiedName $name, bool $ifExists, bool $cascade): string
    {
        $sql = 'DROP VIEW' . ($ifExists ? ' IF EXISTS' : '') . ' ' . $this->quoteQualifiedName($name);

        return $cascade ? $sql . ' CASCADE' : $sql;
    }

    #[\Override]
    public function renderTruncateStatement(QualifiedName $table, bool $cascade, bool $restartIdentity): string
    {
        $sql = 'TRUNCATE TABLE ' . $this->quoteQualifiedName($table);
        if ($restartIdentity) {
            $sql .= ' RESTART IDENTITY';
        }

        return $cascade ? $sql . ' CASCADE' : $sql;
    }

    #[\Override]
    public function renderCreateSequenceStatement(
        Identifier $name,
        int $start,
        int $increment,
        ?int $min,
        ?int $max,
        bool $cycle,
        ?int $cache,
    ): string {
        $sql = 'CREATE SEQUENCE ' . $this->quoteIdentifier($name)
            . ' START WITH ' . $start . ' INCREMENT BY ' . $increment;
        if ($min !== null) {
            $sql .= ' MINVALUE ' . $min;
        }
        if ($max !== null) {
            $sql .= ' MAXVALUE ' . $max;
        }
        if ($cycle) {
            $sql .= ' CYCLE';
        }
        if ($cache !== null) {
            $sql .= ' CACHE ' . $cache;
        }

        return $sql;
    }

    #[\Override]
    public function renderDropSequenceStatement(Identifier $name, bool $ifExists): string
    {
        return 'DROP SEQUENCE' . ($ifExists ? ' IF EXISTS' : '') . ' ' . $this->quoteIdentifier($name);
    }

    #[\Override]
    public function renderCreateDatabaseStatement(Identifier $name, ?string $charset, ?string $collation, bool $ifNotExists): string
    {
        $sql = 'CREATE DATABASE' . ($ifNotExists ? ' IF NOT EXISTS' : '') . ' ' . $this->quoteIdentifier($name);
        if ($charset !== null) {
            $sql .= ' CHARACTER SET ' . $charset;
        }
        if ($collation !== null) {
            $sql .= ' COLLATE ' . $collation;
        }

        return $sql;
    }

    #[\Override]
    public function renderDropDatabaseStatement(Identifier $name, bool $ifExists): string
    {
        return 'DROP DATABASE' . ($ifExists ? ' IF EXISTS' : '') . ' ' . $this->quoteIdentifier($name);
    }

    #[\Override]
    public function renderCreateSchemaStatement(Identifier $name, ?string $authorization, bool $ifNotExists): string
    {
        $sql = 'CREATE SCHEMA' . ($ifNotExists ? ' IF NOT EXISTS' : '') . ' ' . $this->quoteIdentifier($name);

        return $authorization === null ? $sql : $sql . ' AUTHORIZATION ' . $authorization;
    }

    #[\Override]
    public function renderDropSchemaStatement(Identifier $name, bool $ifExists, bool $cascade): string
    {
        $sql = 'DROP SCHEMA' . ($ifExists ? ' IF EXISTS' : '') . ' ' . $this->quoteIdentifier($name);

        return $cascade ? $sql . ' CASCADE' : $sql;
    }

    #[\Override]
    public function renderUseDatabaseStatement(Identifier $database): string
    {
        return 'USE ' . $this->quoteIdentifier($database);
    }

    #[\Override]
    public function renderCreateTriggerStatement(
        QualifiedName $name,
        QualifiedName $table,
        TriggerTiming $timing,
        TriggerEvent $event,
        string $body,
        ?string $definer,
        string $forEach,
    ): string {
        $definerSql = $definer === null ? '' : ' DEFINER = ' . $definer;

        return 'CREATE' . $definerSql . ' TRIGGER ' . $this->quoteQualifiedName($name)
            . ' ' . $timing->value . ' ' . $event->value . ' ON ' . $this->quoteQualifiedName($table)
            . ' FOR EACH ' . $forEach . ' ' . $body;
    }

    #[\Override]
    public function renderDropTriggerStatement(QualifiedName $name, ?QualifiedName $table, bool $ifExists): string
    {
        return 'DROP TRIGGER' . ($ifExists ? ' IF EXISTS' : '') . ' ' . $this->quoteQualifiedName($name);
    }

    /** @param list<RoutineParameterDefinitionInterface> $parameters */
    #[\Override]
    public function renderCreateRoutineStatement(
        QualifiedName $name,
        string $type,
        array $parameters,
        ?DataType $returnType,
        string $body,
        ?string $language,
        bool $deterministic,
        bool $orReplace,
    ): string {
        $params = implode(', ', array_map(
            fn (RoutineParameterDefinitionInterface $parameter): string => $parameter->getDirection()->value . ' '
                . $this->quoteIdentifier(new Identifier($parameter->getName())) . ' ' . $parameter->getDataType()->name,
            $parameters,
        ));
        $sql = 'CREATE ' . ($orReplace ? 'OR REPLACE ' : '') . $type . ' ' . $this->quoteQualifiedName($name)
            . '(' . $params . ')';
        if ($returnType instanceof DataType) {
            $sql .= ' RETURNS ' . $returnType->name;
        }
        if ($language !== null) {
            $sql .= ' LANGUAGE ' . $language;
        }
        if ($deterministic) {
            $sql .= ' DETERMINISTIC';
        }

        return $sql . ' AS ' . $body;
    }

    #[\Override]
    public function renderDropRoutineStatement(QualifiedName $name, string $type, bool $ifExists): string
    {
        return 'DROP ' . $type . ($ifExists ? ' IF EXISTS' : '') . ' ' . $this->quoteQualifiedName($name);
    }

    /** @return list<string> */
    #[\Override]
    public function renderDdlAlterTable(AlterTableDefinitionInterface $alterTable): array
    {
        $table = $alterTable->getTable();
        $statements = [];

        foreach ($alterTable->getAddColumns() as [$column, $after]) {
            if ($after instanceof Identifier) {
                throw CapabilityNotSupportedException::for(Capability::MoveColumn, $this->getName());
            }
            $statements[] = $this->renderAlterTableAddColumn($table, $this->toColumnMeta($column));
        }
        foreach ($alterTable->getModifyColumns() as [$new, $original]) {
            $definition = $this->renderDdlColumnDefinition($new);
            $columnPrefix = $this->quoteIdentifier(new Identifier($new->getName())) . ' ';
            $typeAndAttributes = str_starts_with($definition, $columnPrefix)
                ? substr($definition, strlen($columnPrefix))
                : $definition;
            $statements[] = 'ALTER TABLE ' . $this->quoteQualifiedName($table)
                . ' ALTER COLUMN ' . $this->quoteIdentifier(new Identifier($original->getName()))
                . ' ' . $typeAndAttributes;
        }
        foreach ($alterTable->getDropColumns() as $column) {
            $statements[] = $this->renderAlterTableDropColumn($table, $column);
        }
        foreach ($alterTable->getAddIndexes() as $index) {
            $statements[] = $this->renderDdlCreateIndexStatement($table, $index);
        }
        foreach ($alterTable->getDropIndexes() as $index) {
            $statements[] = $this->renderDropIndexStatement($table, $index);
        }
        foreach ($alterTable->getAddForeignKeys() as $foreignKey) {
            $statements[] = 'ALTER TABLE ' . $this->quoteQualifiedName($table)
                . ' ADD ' . $this->renderDdlForeignKeyClause($foreignKey);
        }
        foreach ($alterTable->getDropForeignKeys() as $constraint) {
            $statements[] = 'ALTER TABLE ' . $this->quoteQualifiedName($table)
                . ' DROP CONSTRAINT ' . $this->quoteIdentifier($constraint);
        }
        foreach ($alterTable->getAddCheckConstraints() as $check) {
            $statements[] = 'ALTER TABLE ' . $this->quoteQualifiedName($table)
                . ' ADD ' . $this->renderDdlCheckConstraintClause($check);
        }
        foreach ($alterTable->getDropCheckConstraints() as $constraint) {
            $statements[] = 'ALTER TABLE ' . $this->quoteQualifiedName($table)
                . ' DROP CONSTRAINT ' . $this->quoteIdentifier($constraint);
        }
        $rename = $alterTable->getRename();
        if ($rename instanceof Identifier) {
            $statements[] = 'ALTER TABLE ' . $this->quoteQualifiedName($table)
                . ' RENAME TO ' . $this->quoteIdentifier($rename);
        }

        return $statements;
    }

    #[\Override]
    public function renderDdlColumnDefinition(ColumnDefinitionInterface $column): string
    {
        return $this->renderColumnDefinition($this->toColumnMeta($column));
    }

    #[\Override]
    public function renderDdlPrimaryKeyClause(IndexDefinitionInterface $index): string
    {
        return $this->renderPrimaryKeyClause($this->toIndexMeta($index));
    }

    #[\Override]
    public function renderDdlForeignKeyClause(ForeignKeyDefinitionInterface $foreignKey): string
    {
        return $this->renderForeignKeyClause($this->toForeignKeyMeta($foreignKey));
    }

    #[\Override]
    public function renderDdlCheckConstraintClause(CheckConstraintDefinitionInterface $check): string
    {
        return $this->renderCheckConstraintClause($this->toCheckConstraintMeta($check));
    }

    #[\Override]
    public function renderDdlCreateIndexStatement(\SQLCraft\ValueObjects\QualifiedName $table, IndexDefinitionInterface $index): string
    {
        return $this->renderCreateIndexStatement($table, $this->toIndexMeta($index));
    }

    #[\Override]
    public function renderColumnDefinition(ColumnMeta $column): string
    {
        return $this->quoteIdentifier(new Identifier($column->name)) . ' ' . $column->dataType->name;
    }

    /**
     * @param list<string> $columnClauses
     * @param list<string> $constraintClauses
     * @param array<string, scalar|null> $tableOptions
     */
    #[\Override]
    public function renderCreateTableStatement(QualifiedName $table, array $columnClauses, array $constraintClauses, array $tableOptions): string
    {
        return 'CREATE TABLE ' . $this->quoteQualifiedName($table) . ' (' . implode(', ', [...$columnClauses, ...$constraintClauses]) . ')';
    }

    #[\Override]
    public function renderPrimaryKeyClause(IndexMeta $index): string
    {
        return 'PRIMARY KEY (' . implode(', ', array_map(fn (\SQLCraft\DTO\IndexColumnMeta $column): string => $this->quoteIdentifier(new Identifier($column->columnName)), $index->columns)) . ')';
    }

    #[\Override]
    public function renderForeignKeyClause(ForeignKeyMeta $foreignKey): string
    {
        return 'CONSTRAINT ' . $this->quoteIdentifier(new Identifier($foreignKey->constraintName))
            . ' FOREIGN KEY (' . implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier(new Identifier($column)), $foreignKey->sourceColumns)) . ')'
            . ' REFERENCES ' . $this->quoteIdentifier(new Identifier($foreignKey->targetTable));
    }

    #[\Override]
    public function renderCheckConstraintClause(CheckConstraintMeta $check): string
    {
        return 'CONSTRAINT ' . $this->quoteIdentifier(new Identifier($check->name)) . ' CHECK (' . $check->expression . ')';
    }

    private function toColumnMeta(ColumnDefinitionInterface $column): ColumnMeta
    {
        return new ColumnMeta(
            name: $column->getName(),
            dataType: $column->getDataType(),
            nullable: $column->isNullable(),
            autoIncrement: $column->isAutoIncrement(),
            primary: $column->isPrimary(),
            generated: $column->isGenerated(),
            default: $column->getDefault(),
            collation: $column->getCollation(),
            comment: $column->getComment(),
            onUpdate: $column->getOnUpdate(),
            privileges: $column->getPrivileges(),
            origName: $column->getOriginalName(),
            defaultConstraintName: $column->getDefaultConstraintName(),
        );
    }

    private function toIndexMeta(IndexDefinitionInterface $index): IndexMeta
    {
        return new IndexMeta(
            name: $index->getName(),
            type: $index->getType(),
            columns: array_map(
                static fn (\SQLCraft\Contracts\DDL\IndexColumnDefinitionInterface $column): IndexColumnMeta => new IndexColumnMeta(
                    columnName: $column->getColumnName(),
                    descending: $column->isDescending(),
                    length: $column->getLength(),
                    expression: $column->getExpression(),
                ),
                $index->getColumns(),
            ),
            unique: $index->isUnique(),
            comment: $index->getComment(),
            algorithm: $index->getAlgorithm(),
            filterExpression: $index->getFilterExpression(),
        );
    }

    private function toForeignKeyMeta(ForeignKeyDefinitionInterface $foreignKey): ForeignKeyMeta
    {
        return new ForeignKeyMeta(
            constraintName: $foreignKey->getConstraintName(),
            targetDatabase: $foreignKey->getTargetDatabase(),
            targetSchema: $foreignKey->getTargetSchema(),
            targetTable: $foreignKey->getTargetTable(),
            sourceColumns: $foreignKey->getSourceColumns(),
            targetColumns: $foreignKey->getTargetColumns(),
            onDelete: $foreignKey->getOnDelete(),
            onUpdate: $foreignKey->getOnUpdate(),
            definition: $foreignKey->getDefinition(),
            deferrable: $foreignKey->isDeferrable(),
        );
    }

    private function toCheckConstraintMeta(CheckConstraintDefinitionInterface $check): CheckConstraintMeta
    {
        return new CheckConstraintMeta(
            name: $check->getName(),
            expression: $check->getExpression(),
            enforced: $check->isEnforced(),
        );
    }

    private function quoteQualifiedName(QualifiedName $table): string
    {
        $parts = [];
        if ($table->catalog instanceof Identifier) {
            $parts[] = $this->quoteIdentifier($table->catalog);
        }
        if ($table->schema instanceof Identifier) {
            $parts[] = $this->quoteIdentifier($table->schema);
        }
        $parts[] = $this->quoteIdentifier($table->object);

        return implode('.', $parts);
    }

    #[\Override]
    public function applySingleRowLimit(string $sql, string $whereClause): string
    {
        return $sql . ' LIMIT 1';
    }

    /** @return list<string> */
    /** @return list<string> */
    #[\Override]
    public function getSupportedAggregateFunctions(): array
    {
        return ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
    }

    #[\Override]
    public function getOperators(): array
    {
        return [
            '=', '!=', '<>', '<', '<=', '>', '>=',
            'LIKE', 'NOT LIKE', 'IN', 'NOT IN',
            'IS NULL', 'IS NOT NULL', 'BETWEEN', 'NOT BETWEEN',
            'REGEXP', 'NOT REGEXP',
        ];
    }

    #[\Override]
    public function getExplainSql(string $sql, bool $analyze = false): string
    {
        return ($analyze ? 'EXPLAIN ANALYZE ' : 'EXPLAIN ') . $sql;
    }

    #[\Override]
    public function wrapWithTimeout(string $sql, int $milliseconds): ?string
    {
        return null;
    }

    #[\Override]
    public function getCapabilitySet(ServerVersion $version): CapabilitySet
    {
        return (new PlatformCapabilityResolver($this->buildCapabilityMatrix()))
            ->resolve($this->getName(), $version);
    }

    /**
     * @return array{always: list<Capability|ExtendedCapability>, versioned: list<array{0: Capability|ExtendedCapability, 1: array{0: int, 1: int, 2: int}}>}
     */
    abstract protected function buildCapabilityMatrix(): array;
}
