<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\DatabaseMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\DTO\PartitionInfo;
use SQLCraft\DTO\ProcessMeta;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\DTO\SchemaMeta;
use SQLCraft\DTO\SequenceMeta;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\DTO\TableStatus;
use SQLCraft\DTO\TriggerMeta;
use SQLCraft\DTO\UserMeta;
use SQLCraft\DTO\ViewMeta;

/**
 * Converts one platform-specific metadata row into a typed snapshot.
 *
 * @internal Metadata services are the only intended consumers.
 */
interface MetadataFactoryInterface
{
    /** @param array<string, bool|float|int|string|null> $row */
    public function createColumnMeta(array $row): ColumnMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createCheckConstraintMeta(array $row): CheckConstraintMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createDatabaseMeta(array $row): DatabaseMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createProcessMeta(array $row): ProcessMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createTableStatus(array $row): TableStatus;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createPartitionInfo(array $row): PartitionInfo;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createSchemaMeta(array $row): SchemaMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createSequenceMeta(array $row): SequenceMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createDataType(array $row): DataType;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createIndexMeta(array $row): IndexMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createForeignKeyMeta(array $row): ForeignKeyMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createTriggerMeta(array $row): TriggerMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createRoutineMeta(array $row): RoutineMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createViewMeta(array $row): ViewMeta;

    /** @param array<string, bool|float|int|string|null> $row */
    public function createUserMeta(array $row): UserMeta;
}
