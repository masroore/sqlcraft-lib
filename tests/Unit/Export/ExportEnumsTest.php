<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\Export\DataStyle;
use SQLCraft\Export\DatabaseSectionStyle;
use SQLCraft\Export\ScopeKind;
use SQLCraft\Export\TableSectionStyle;

final class ExportEnumsTest extends TestCase
{
    public function testDatabaseSectionStyles(): void
    {
        self::assertSame(
            ['None', 'Use', 'DropCreate', 'Create'],
            array_map(static fn (DatabaseSectionStyle $style): string => $style->name, DatabaseSectionStyle::cases()),
        );
    }

    public function testTableAndDataStyles(): void
    {
        self::assertSame(
            ['None', 'DropCreate', 'Create'],
            array_map(static fn (TableSectionStyle $style): string => $style->name, TableSectionStyle::cases()),
        );
        self::assertSame(
            ['None', 'TruncateInsert', 'Insert', 'InsertUpdate'],
            array_map(static fn (DataStyle $style): string => $style->name, DataStyle::cases()),
        );
    }

    public function testScopeKinds(): void
    {
        self::assertSame(
            ['AllDatabases', 'Database', 'Tables', 'FilteredResult'],
            array_map(static fn (ScopeKind $kind): string => $kind->name, ScopeKind::cases()),
        );
    }
}
