<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\Export\DataStyle;
use SQLCraft\Export\DatabaseSectionStyle;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\ScopeKind;
use SQLCraft\Export\TableSectionStyle;

final class DumpOptionsTest extends TestCase
{
    public function testDefaultsMatchThePlannedStreamingExportPolicy(): void
    {
        $options = new DumpOptions('sql', DumpScope::database('shop'));

        self::assertSame('sql', $options->format);
        self::assertSame(ScopeKind::Database, $options->scope->kind);
        self::assertSame(DatabaseSectionStyle::None, $options->databaseStyle);
        self::assertSame(TableSectionStyle::DropCreate, $options->tableStyle);
        self::assertSame(DataStyle::Insert, $options->dataStyle);
        self::assertTrue($options->includeAutoIncrement);
        self::assertFalse($options->includeTriggers);
        self::assertFalse($options->includeRoutines);
        self::assertFalse($options->includeEvents);
        self::assertFalse($options->includeUserTypes);
        self::assertSame(100, $options->batchSize);
        self::assertNull($options->csvSeparator);
        self::assertSame('\N', $options->nullRepresentation);
    }

    public function testAllOptionsCanBeConfigured(): void
    {
        $scope = DumpScope::table('shop', 'orders');
        $options = new DumpOptions(
            format: 'csv-semicolon',
            scope: $scope,
            databaseStyle: DatabaseSectionStyle::Create,
            tableStyle: TableSectionStyle::Create,
            dataStyle: DataStyle::InsertUpdate,
            includeAutoIncrement: false,
            includeTriggers: true,
            includeRoutines: true,
            includeEvents: true,
            includeUserTypes: true,
            batchSize: 25,
            csvSeparator: ';',
            nullRepresentation: 'NULL',
        );

        self::assertSame($scope, $options->scope);
        self::assertSame(DatabaseSectionStyle::Create, $options->databaseStyle);
        self::assertSame(TableSectionStyle::Create, $options->tableStyle);
        self::assertSame(DataStyle::InsertUpdate, $options->dataStyle);
        self::assertFalse($options->includeAutoIncrement);
        self::assertTrue($options->includeTriggers);
        self::assertTrue($options->includeRoutines);
        self::assertTrue($options->includeEvents);
        self::assertTrue($options->includeUserTypes);
        self::assertSame(25, $options->batchSize);
        self::assertSame(';', $options->csvSeparator);
        self::assertSame('NULL', $options->nullRepresentation);
    }
}
