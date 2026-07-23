<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\AlterTableDefinitionInterface;
use SQLCraft\Contracts\DDL\CheckConstraintDefinitionInterface;
use SQLCraft\Contracts\DDL\ColumnDefinitionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ForeignKeyDefinitionInterface;
use SQLCraft\Contracts\DDL\IndexDefinitionInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class AlterTableBuilder implements AlterTableDefinitionInterface, DdlBuilderInterface, ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;

    /**
     * @param  list<array{0: ColumnDefinitionInterface, 1: ?Identifier}>  $addColumns
     * @param  list<array{0: ColumnDefinitionInterface, 1: ColumnDefinitionInterface}>  $modifyColumns
     * @param  list<Identifier>  $dropColumns
     * @param  list<IndexDefinitionInterface>  $addIndexes
     * @param  list<Identifier>  $dropIndexes
     * @param  list<ForeignKeyDefinitionInterface>  $addForeignKeys
     * @param  list<Identifier>  $dropForeignKeys
     * @param  list<CheckConstraintDefinitionInterface>  $addCheckConstraints
     * @param  list<Identifier>  $dropCheckConstraints
     */
    public function __construct(
        private QualifiedName $table,
        private array $addColumns = [],
        private array $modifyColumns = [],
        private array $dropColumns = [],
        private array $addIndexes = [],
        private array $dropIndexes = [],
        private array $addForeignKeys = [],
        private array $dropForeignKeys = [],
        private array $addCheckConstraints = [],
        private array $dropCheckConstraints = [],
        private ?Identifier $rename = null,
    ) {
    }

    public function withColumn(ColumnDefinitionInterface $column, ?Identifier $after = null): self
    {
        return $this->copy(addColumns: [...$this->addColumns, [$column, $after]]);
    }

    public function modifyColumn(ColumnDefinitionInterface $new, ColumnDefinitionInterface $original): self
    {
        return $this->copy(modifyColumns: [...$this->modifyColumns, [$new, $original]]);
    }

    public function dropColumn(Identifier $column): self
    {
        return $this->copy(dropColumns: [...$this->dropColumns, $column]);
    }

    public function withIndex(IndexDefinitionInterface $index): self
    {
        return $this->copy(addIndexes: [...$this->addIndexes, $index]);
    }

    public function dropIndex(Identifier $index): self
    {
        return $this->copy(dropIndexes: [...$this->dropIndexes, $index]);
    }

    public function withForeignKey(ForeignKeyDefinitionInterface $foreignKey): self
    {
        return $this->copy(addForeignKeys: [...$this->addForeignKeys, $foreignKey]);
    }

    public function dropForeignKey(Identifier $constraint): self
    {
        return $this->copy(dropForeignKeys: [...$this->dropForeignKeys, $constraint]);
    }

    public function withCheckConstraint(CheckConstraintDefinitionInterface $check): self
    {
        return $this->copy(addCheckConstraints: [...$this->addCheckConstraints, $check]);
    }

    public function dropCheckConstraint(Identifier $constraint): self
    {
        return $this->copy(dropCheckConstraints: [...$this->dropCheckConstraints, $constraint]);
    }

    public function renameTo(Identifier $table): self
    {
        return $this->copy(rename: $table, replaceRename: true);
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return $dialect->renderDdlAlterTable($this);
    }

    #[\Override]
    public function getTable(): QualifiedName
    {
        return $this->table;
    }

    /** @return list<array{0: ColumnDefinitionInterface, 1: ?Identifier}> */
    #[\Override]
    public function getAddColumns(): array
    {
        return $this->addColumns;
    }

    /** @return list<array{0: ColumnDefinitionInterface, 1: ColumnDefinitionInterface}> */
    #[\Override]
    public function getModifyColumns(): array
    {
        return $this->modifyColumns;
    }

    /** @return list<Identifier> */
    #[\Override]
    public function getDropColumns(): array
    {
        return $this->dropColumns;
    }

    /** @return list<IndexDefinitionInterface> */
    #[\Override]
    public function getAddIndexes(): array
    {
        return $this->addIndexes;
    }

    /** @return list<Identifier> */
    #[\Override]
    public function getDropIndexes(): array
    {
        return $this->dropIndexes;
    }

    /** @return list<ForeignKeyDefinitionInterface> */
    #[\Override]
    public function getAddForeignKeys(): array
    {
        return $this->addForeignKeys;
    }

    /** @return list<Identifier> */
    #[\Override]
    public function getDropForeignKeys(): array
    {
        return $this->dropForeignKeys;
    }

    /** @return list<CheckConstraintDefinitionInterface> */
    #[\Override]
    public function getAddCheckConstraints(): array
    {
        return $this->addCheckConstraints;
    }

    /** @return list<Identifier> */
    #[\Override]
    public function getDropCheckConstraints(): array
    {
        return $this->dropCheckConstraints;
    }

    #[\Override]
    public function getRename(): ?Identifier
    {
        return $this->rename;
    }

    /**
     * @param  list<array{0: ColumnDefinitionInterface, 1: ?Identifier}>|null  $addColumns
     * @param  list<array{0: ColumnDefinitionInterface, 1: ColumnDefinitionInterface}>|null  $modifyColumns
     * @param  list<Identifier>|null  $dropColumns
     * @param  list<IndexDefinitionInterface>|null  $addIndexes
     * @param  list<Identifier>|null  $dropIndexes
     * @param  list<ForeignKeyDefinitionInterface>|null  $addForeignKeys
     * @param  list<Identifier>|null  $dropForeignKeys
     * @param  list<CheckConstraintDefinitionInterface>|null  $addCheckConstraints
     * @param  list<Identifier>|null  $dropCheckConstraints
     */
    private function copy(
        ?array $addColumns = null,
        ?array $modifyColumns = null,
        ?array $dropColumns = null,
        ?array $addIndexes = null,
        ?array $dropIndexes = null,
        ?array $addForeignKeys = null,
        ?array $dropForeignKeys = null,
        ?array $addCheckConstraints = null,
        ?array $dropCheckConstraints = null,
        ?Identifier $rename = null,
        bool $replaceRename = false,
    ): self {
        return new self(
            table: $this->table,
            addColumns: $addColumns ?? $this->addColumns,
            modifyColumns: $modifyColumns ?? $this->modifyColumns,
            dropColumns: $dropColumns ?? $this->dropColumns,
            addIndexes: $addIndexes ?? $this->addIndexes,
            dropIndexes: $dropIndexes ?? $this->dropIndexes,
            addForeignKeys: $addForeignKeys ?? $this->addForeignKeys,
            dropForeignKeys: $dropForeignKeys ?? $this->dropForeignKeys,
            addCheckConstraints: $addCheckConstraints ?? $this->addCheckConstraints,
            dropCheckConstraints: $dropCheckConstraints ?? $this->dropCheckConstraints,
            rename: $replaceRename ? $rename : $this->rename,
        );
    }

    #[\Override]
    public function getObjectName(): string
    {
        return $this->table->object->name;
    }
}
