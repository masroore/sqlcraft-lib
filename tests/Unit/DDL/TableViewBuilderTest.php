<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\DDL\CreateViewBuilder;
use SQLCraft\DDL\DropViewBuilder;
use SQLCraft\DDL\TruncateBuilder;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class TableViewBuilderTest extends TestCase
{
    public function test_lifecycle_builders_delegate_immutable_intent(): void
    {
        $name = new QualifiedName(new Identifier('active_users'));
        $dialect = self::createMock(DdlDialectInterface::class);
        $dialect->expects(self::once())->method('renderTruncateStatement')->with($name, true, true)->willReturn('TRUNCATE');
        $dialect->expects(self::once())->method('renderCreateViewStatement')->with($name, 'SELECT 1', true, [], 'LOCAL')->willReturn('CREATE VIEW');
        $dialect->expects(self::once())->method('renderDropViewStatement')->with($name, true, true)->willReturn('DROP VIEW');

        self::assertSame(['TRUNCATE'], (new TruncateBuilder($name, true, true))->toSql($dialect));
        self::assertSame(['CREATE VIEW'], (new CreateViewBuilder($name, 'SELECT 1', true, [], 'LOCAL'))->toSql($dialect));
        self::assertSame(['DROP VIEW'], (new DropViewBuilder($name, true, true))->toSql($dialect));
    }

    public function test_sqlite_uses_delete_for_truncate(): void
    {
        $table = new QualifiedName(new Identifier('users'));

        self::assertSame(['DELETE FROM "users"'], (new TruncateBuilder($table))->toSql(new SqlitePlatform));
        self::assertSame(['CREATE VIEW "active_users" AS SELECT 1'], (new CreateViewBuilder(
            new QualifiedName(new Identifier('active_users')),
            'SELECT 1',
        ))->toSql(new SqlitePlatform));
        self::assertSame(['DROP VIEW IF EXISTS "active_users"'], (new DropViewBuilder(
            new QualifiedName(new Identifier('active_users')),
            true,
        ))->toSql(new SqlitePlatform));
    }
}
