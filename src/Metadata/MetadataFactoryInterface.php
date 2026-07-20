<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\DTO\PartitionInfo;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\DTO\SchemaMeta;
use SQLCraft\DTO\SequenceMeta;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\DTO\TableStatus;
use SQLCraft\DTO\TriggerMeta;

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
}
