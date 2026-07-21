<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\DDL\CreateDatabaseBuilder;
use SQLCraft\DDL\CreateSchemaBuilder;
use SQLCraft\DDL\CreateSequenceBuilder;
use SQLCraft\DDL\DropDatabaseBuilder;
use SQLCraft\DDL\DropSchemaBuilder;
use SQLCraft\DDL\DropSequenceBuilder;
use SQLCraft\DDL\UseDatabaseBuilder;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\Identifier;

final class SequenceSchemaBuilderTest extends TestCase
{
    public function testSequenceDatabaseSchemaBuildersDelegate(): void
    {
        $sequence = new Identifier('user_ids');
        $database = new Identifier('analytics');
        $schema = new Identifier('reporting');
        $dialect = self::createMock(DdlDialectInterface::class);
        $dialect->expects(self::once())->method('renderCreateSequenceStatement')->with($sequence, 10, 2, 1, 1000, true, 20)->willReturn('CREATE SEQUENCE');
        $dialect->expects(self::once())->method('renderDropSequenceStatement')->with($sequence, true)->willReturn('DROP SEQUENCE');
        $dialect->expects(self::once())->method('renderCreateDatabaseStatement')->with($database, 'utf8mb4', 'utf8mb4_bin', true)->willReturn('CREATE DATABASE');
        $dialect->expects(self::once())->method('renderDropDatabaseStatement')->with($database, true)->willReturn('DROP DATABASE');
        $dialect->expects(self::once())->method('renderCreateSchemaStatement')->with($schema, 'owner', true)->willReturn('CREATE SCHEMA');
        $dialect->expects(self::once())->method('renderDropSchemaStatement')->with($schema, true, true)->willReturn('DROP SCHEMA');
        $dialect->expects(self::once())->method('renderUseDatabaseStatement')->with($database)->willReturn('USE DATABASE');

        self::assertSame(['CREATE SEQUENCE'], (new CreateSequenceBuilder($sequence, 10, 2, 1, 1000, true, 20))->toSql($dialect));
        self::assertSame(['DROP SEQUENCE'], (new DropSequenceBuilder($sequence, true))->toSql($dialect));
        self::assertSame(['CREATE DATABASE'], (new CreateDatabaseBuilder($database, 'utf8mb4', 'utf8mb4_bin', true))->toSql($dialect));
        self::assertSame(['DROP DATABASE'], (new DropDatabaseBuilder($database, true))->toSql($dialect));
        self::assertSame(['CREATE SCHEMA'], (new CreateSchemaBuilder($schema, 'owner', true))->toSql($dialect));
        self::assertSame(['DROP SCHEMA'], (new DropSchemaBuilder($schema, true, true))->toSql($dialect));
        self::assertSame(['USE DATABASE'], (new UseDatabaseBuilder($database))->toSql($dialect));
    }

    public function testSqliteRendersPortableDatabaseAndSchemaOperations(): void
    {
        $sqlite = new SqlitePlatform();
        self::assertSame(['CREATE SEQUENCE "user_ids" START WITH 1 INCREMENT BY 1'], (new CreateSequenceBuilder(new Identifier('user_ids')))->toSql($sqlite));
        self::assertSame(['DROP SEQUENCE IF EXISTS "user_ids"'], (new DropSequenceBuilder(new Identifier('user_ids'), true))->toSql($sqlite));
        self::assertSame(['CREATE DATABASE IF NOT EXISTS "analytics"'], (new CreateDatabaseBuilder(new Identifier('analytics'), null, null, true))->toSql($sqlite));
        self::assertSame(['DROP DATABASE "analytics"'], (new DropDatabaseBuilder(new Identifier('analytics')))->toSql($sqlite));
        self::assertSame(['CREATE SCHEMA "reporting"'], (new CreateSchemaBuilder(new Identifier('reporting')))->toSql($sqlite));
        self::assertSame(['DROP SCHEMA "reporting"'], (new DropSchemaBuilder(new Identifier('reporting')))->toSql($sqlite));
        self::assertSame(['USE "analytics"'], (new UseDatabaseBuilder(new Identifier('analytics')))->toSql($sqlite));
    }

    public function testMysqlRejectsNativeSequences(): void
    {
        $this->expectException(CapabilityNotSupportedException::class);

        (new CreateSequenceBuilder(new Identifier('user_ids')))->toSql(new MySQLPlatform());
    }
}
