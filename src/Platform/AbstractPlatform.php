<?php

declare(strict_types=1);

namespace SQLCraft\Platform;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\DDL\CheckConstraintDefinitionInterface;
use SQLCraft\Contracts\DDL\ColumnDefinitionInterface;
use SQLCraft\Contracts\DDL\ForeignKeyDefinitionInterface;
use SQLCraft\Contracts\DDL\IndexDefinitionInterface;
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
