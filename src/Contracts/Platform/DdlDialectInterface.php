<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

use SQLCraft\Contracts\DDL\AlterTableDefinitionInterface;
use SQLCraft\Contracts\DDL\CheckConstraintDefinitionInterface;
use SQLCraft\Contracts\DDL\ColumnDefinitionInterface;
use SQLCraft\Contracts\DDL\ForeignKeyDefinitionInterface;
use SQLCraft\Contracts\DDL\IndexDefinitionInterface;
use SQLCraft\Contracts\DDL\RoutineParameterDefinitionInterface;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

interface DdlDialectInterface
{
    /** @return list<string> */
    public function renderDdlAlterTable(AlterTableDefinitionInterface $alterTable): array;

    /** @param list<Identifier> $columns */
    public function renderCreateViewStatement(QualifiedName $name, string $selectSql, bool $orReplace, array $columns, ?string $checkOption): string;

    public function renderDropViewStatement(QualifiedName $name, bool $ifExists, bool $cascade): string;

    public function renderTruncateStatement(QualifiedName $table, bool $cascade, bool $restartIdentity): string;

    public function renderCreateTriggerStatement(
        QualifiedName $name,
        QualifiedName $table,
        TriggerTiming $timing,
        TriggerEvent $event,
        string $body,
        ?string $definer,
        string $forEach,
    ): string;

    public function renderDropTriggerStatement(QualifiedName $name, ?QualifiedName $table, bool $ifExists): string;

    /** @param list<RoutineParameterDefinitionInterface> $parameters */
    public function renderCreateRoutineStatement(
        QualifiedName $name,
        string $type,
        array $parameters,
        ?DataType $returnType,
        string $body,
        ?string $language,
        bool $deterministic,
        bool $orReplace,
    ): string;

    public function renderDropRoutineStatement(QualifiedName $name, string $type, bool $ifExists): string;


    public function renderDdlColumnDefinition(ColumnDefinitionInterface $column): string;

    public function renderDdlPrimaryKeyClause(IndexDefinitionInterface $index): string;

    public function renderDdlForeignKeyClause(ForeignKeyDefinitionInterface $foreignKey): string;

    public function renderDdlCheckConstraintClause(CheckConstraintDefinitionInterface $check): string;

    public function renderColumnDefinition(ColumnMeta $column): string;

    public function renderPrimaryKeyClause(IndexMeta $index): string;

    public function renderForeignKeyClause(ForeignKeyMeta $foreignKey): string;

    public function renderCheckConstraintClause(CheckConstraintMeta $check): string;

    /**
     * @param list<string> $columnClauses
     * @param list<string> $constraintClauses
     * @param array<string, scalar|null> $tableOptions
     */
    public function renderCreateTableStatement(
        QualifiedName $table,
        array $columnClauses,
        array $constraintClauses,
        array $tableOptions,
    ): string;

    public function renderDropTableStatement(QualifiedName $table, bool $ifExists, bool $cascade): string;

    public function renderAlterTableAddColumn(QualifiedName $table, ColumnMeta $column): string;

    public function renderAlterTableDropColumn(QualifiedName $table, Identifier $column): string;

    public function renderCreateIndexStatement(QualifiedName $table, IndexMeta $index): string;

    public function renderDdlCreateIndexStatement(QualifiedName $table, IndexDefinitionInterface $index): string;

    public function renderDropIndexStatement(QualifiedName $table, Identifier $indexName): string;
}
