<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\DatabaseMeta;
use SQLCraft\DTO\SchemaMeta;

final class DatabaseSchemaDtoTest extends TestCase
{
    public function testDatabaseMetaStoresDatabaseIdentityAndDefaults(): void
    {
        $database = new DatabaseMeta(
            name: 'shop',
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci',
        );

        self::assertSame('shop', $database->name);
        self::assertSame('utf8mb4', $database->charset);
        self::assertSame('utf8mb4_unicode_ci', $database->collation);
    }

    public function testDatabaseMetaSupportsEnginesWithoutDatabaseCharsetMetadata(): void
    {
        $database = new DatabaseMeta(
            name: 'main',
            charset: null,
            collation: null,
        );

        self::assertSame('main', $database->name);
        self::assertNull($database->charset);
        self::assertNull($database->collation);
    }

    public function testSchemaMetaStoresCatalogAndOwner(): void
    {
        $schema = new SchemaMeta(
            name: 'public',
            catalog: 'shop',
            owner: 'app_user',
        );

        self::assertSame('public', $schema->name);
        self::assertSame('shop', $schema->catalog);
        self::assertSame('app_user', $schema->owner);
    }

    public function testSchemaMetaSupportsSchemasWithoutCatalogOrOwner(): void
    {
        $schema = new SchemaMeta(
            name: 'dbo',
            catalog: null,
            owner: null,
        );

        self::assertSame('dbo', $schema->name);
        self::assertNull($schema->catalog);
        self::assertNull($schema->owner);
    }
}
