<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

use SQLCraft\ValueObjects\QualifiedName;

interface IntrospectionDialectInterface
{
    public function getDatabasesSql(): string;

    public function getTablesSql(string $database, ?string $schema = null): string;

    public function getColumnsSql(QualifiedName $table): string;

    public function getTableStatusSql(QualifiedName $table): string;

    public function getParentTablesSql(QualifiedName $table): string;

    public function getPartitionsSql(QualifiedName $table): string;

    public function getIndexesSql(QualifiedName $table): string;

    public function getForeignKeysSql(QualifiedName $table): string;

    public function getReferencingForeignKeysSql(QualifiedName $table): string;

    public function getTriggersSql(QualifiedName $table): string;

    public function getRoutinesSql(?string $schema = null): string;

    public function getSequencesSql(?string $schema = null): string;

    public function getVariablesSql(): string;

    public function getProcesslistSql(): string;
}
