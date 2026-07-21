<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\ScopeKind;

final class DumpScopeTest extends TestCase
{
    public function testAllDatabasesScope(): void
    {
        $scope = DumpScope::allDatabases();

        self::assertSame(ScopeKind::AllDatabases, $scope->kind);
        self::assertNull($scope->database);
        self::assertNull($scope->tables);
        self::assertNull($scope->resultSql);
    }

    public function testDatabaseScope(): void
    {
        $scope = DumpScope::database('shop');

        self::assertSame(ScopeKind::Database, $scope->kind);
        self::assertSame('shop', $scope->database);
        self::assertNull($scope->tables);
        self::assertNull($scope->resultSql);
    }

    public function testTablesAndSingleTableScopes(): void
    {
        $tables = DumpScope::tables('shop', ['orders', 'customers']);
        $table = DumpScope::table('shop', 'orders');

        self::assertSame(ScopeKind::Tables, $tables->kind);
        self::assertSame('shop', $tables->database);
        self::assertSame(['orders', 'customers'], $tables->tables);
        self::assertSame(['orders'], $table->tables);
    }

    public function testRejectsEmptyScopeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DumpScope::tables('shop', []);
    }

    public function testFilteredResultScope(): void
    {
        $scope = DumpScope::filteredResult('shop', 'orders', 'SELECT * FROM orders WHERE id > 10');

        self::assertSame(ScopeKind::FilteredResult, $scope->kind);
        self::assertSame('shop', $scope->database);
        self::assertSame(['orders'], $scope->tables);
        self::assertSame('SELECT * FROM orders WHERE id > 10', $scope->resultSql);
    }
}
