<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

use SQLCraft\ValueObjects\QualifiedName;

interface IntrospectionDialectInterface
{
    public function getDatabasesSql(): string;

    public function getSchemasSql(): string;

    public function getTypesSql(?string $schema = null): string;

    public function getTablesSql(string $database, ?string $schema = null): string;

    public function getColumnsSql(QualifiedName $table): string;

    public function getTableStatusSql(QualifiedName $table): string;

    public function getViewsSql(?string $schema = null): string;

    public function getViewDefinitionSql(QualifiedName $view): string;

    public function getMaterializedViewsSql(?string $schema = null): string;

    public function getParentTablesSql(QualifiedName $table): string;

    public function getPartitionsSql(QualifiedName $table): string;

    public function getIndexesSql(QualifiedName $table): string;

    public function getForeignKeysSql(QualifiedName $table): string;

    public function getReferencingForeignKeysSql(QualifiedName $table): string;

    public function getTriggersSql(QualifiedName $table): string;

    public function getRoutinesSql(?string $schema = null): string;

    public function getRoutineDetailSql(QualifiedName $routine): string;

    public function getCheckConstraintsSql(QualifiedName $table): string;

    public function getUsersSql(): string;

    public function getSequencesSql(?string $schema = null): string;

    public function getVariablesSql(): string;

    public function getStatusSql(): string;

    public function getCharsetsSql(): string;

    public function getCollationsSql(?string $charset = null): string;

    public function getProcesslistSql(): string;
}
