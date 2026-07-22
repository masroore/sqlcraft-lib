<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\DatabaseMeta;
use SQLCraft\DTO\SchemaMeta;

final class DatabaseSchemaDtoTest extends TestCase
{
    public function test_database_meta_stores_database_identity_and_defaults(): void
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

    public function test_database_meta_supports_engines_without_database_charset_metadata(): void
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

    public function test_schema_meta_stores_catalog_and_owner(): void
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

    public function test_schema_meta_supports_schemas_without_catalog_or_owner(): void
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
