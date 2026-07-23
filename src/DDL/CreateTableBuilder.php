<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\CheckConstraintDefinitionInterface;
use SQLCraft\Contracts\DDL\ColumnDefinitionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ForeignKeyDefinitionInterface;
use SQLCraft\Contracts\DDL\IndexDefinitionInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class CreateTableBuilder implements DdlBuilderInterface, ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;

    /**
     * @param  list<ColumnDefinitionInterface>  $columns
     * @param  list<IndexDefinitionInterface>  $indexes
     * @param  list<ForeignKeyDefinitionInterface>  $foreignKeys
     * @param  list<CheckConstraintDefinitionInterface>  $checkConstraints
     */
    public function __construct(
        public QualifiedName $table,
        public array $columns = [],
        public array $indexes = [],
        public array $foreignKeys = [],
        public array $checkConstraints = [],
        public ?string $engine = null,
        public ?string $charset = null,
        public ?string $collation = null,
        public ?string $comment = null,
        public bool $ifNotExists = false,
        public bool $temporary = false,
        public bool $includeAutoIncrementValue = false,
        public ?int $autoIncrementValue = null,
    ) {
    }

    public function withColumn(ColumnDefinitionInterface $column): self
    {
        return new self(table: $this->table, columns: [...$this->columns, $column], indexes: $this->indexes, foreignKeys: $this->foreignKeys, checkConstraints: $this->checkConstraints, engine: $this->engine, charset: $this->charset, collation: $this->collation, comment: $this->comment, ifNotExists: $this->ifNotExists, temporary: $this->temporary, includeAutoIncrementValue: $this->includeAutoIncrementValue, autoIncrementValue: $this->autoIncrementValue);
    }

    public function withIndex(IndexDefinitionInterface $index): self
    {
        return new self(table: $this->table, columns: $this->columns, indexes: [...$this->indexes, $index], foreignKeys: $this->foreignKeys, checkConstraints: $this->checkConstraints, engine: $this->engine, charset: $this->charset, collation: $this->collation, comment: $this->comment, ifNotExists: $this->ifNotExists, temporary: $this->temporary, includeAutoIncrementValue: $this->includeAutoIncrementValue, autoIncrementValue: $this->autoIncrementValue);
    }

    public function withForeignKey(ForeignKeyDefinitionInterface $foreignKey): self
    {
        return new self(table: $this->table, columns: $this->columns, indexes: $this->indexes, foreignKeys: [...$this->foreignKeys, $foreignKey], checkConstraints: $this->checkConstraints, engine: $this->engine, charset: $this->charset, collation: $this->collation, comment: $this->comment, ifNotExists: $this->ifNotExists, temporary: $this->temporary, includeAutoIncrementValue: $this->includeAutoIncrementValue, autoIncrementValue: $this->autoIncrementValue);
    }

    public function withoutAutoIncrementValue(): self
    {
        return new self(table: $this->table, columns: $this->columns, indexes: $this->indexes, foreignKeys: $this->foreignKeys, checkConstraints: $this->checkConstraints, engine: $this->engine, charset: $this->charset, collation: $this->collation, comment: $this->comment, ifNotExists: $this->ifNotExists, temporary: $this->temporary, includeAutoIncrementValue: false);
    }

    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        $constraints = [];
        foreach ($this->indexes as $index) {
            if ($index->getType() === IndexType::PRIMARY) {
                $constraints[] = $dialect->renderDdlPrimaryKeyClause($index);
            }
        }
        foreach ($this->foreignKeys as $foreignKey) {
            $constraints[] = $dialect->renderDdlForeignKeyClause($foreignKey);
        }
        foreach ($this->checkConstraints as $checkConstraint) {
            $constraints[] = $dialect->renderDdlCheckConstraintClause($checkConstraint);
        }

        return [$dialect->renderCreateTableStatement(
            $this->table,
            array_map($dialect->renderDdlColumnDefinition(...), $this->columns),
            $constraints,
            $this->options(),
        )];
    }

    /** @return array<string, scalar|null> */
    private function options(): array
    {
        return [
            'engine' => $this->engine,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'comment' => $this->comment,
            'if_not_exists' => $this->ifNotExists,
            'temporary' => $this->temporary,
            'include_auto_increment_value' => $this->includeAutoIncrementValue,
            'auto_increment_value' => $this->autoIncrementValue,
        ];
    }

    #[\Override]
    public function getObjectName(): string
    {
        return $this->table->object->name;
    }
}
