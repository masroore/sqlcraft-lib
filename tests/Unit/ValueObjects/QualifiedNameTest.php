<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class QualifiedNameTest extends TestCase
{
    public function test_it_stores_object_schema_and_catalog(): void
    {
        $name = new QualifiedName(
            new Identifier('users'),
            new Identifier('public'),
            new Identifier('app'),
        );

        self::assertSame('users', $name->object->name);
        self::assertSame('public', $name->schema?->name);
        self::assertSame('app', $name->catalog?->name);
    }

    public function test_qualify_returns_a_value_with_requested_depth(): void
    {
        $name = new QualifiedName(
            new Identifier('users'),
            new Identifier('public'),
            new Identifier('app'),
        );

        $objectOnly = $name->qualify(1);
        $schemaQualified = $name->qualify(2);

        self::assertNull($objectOnly->schema);
        self::assertNull($objectOnly->catalog);
        self::assertSame('public', $schemaQualified->schema?->name);
        self::assertNull($schemaQualified->catalog);
        self::assertSame('public', $name->schema?->name);
        self::assertSame('app', $name->catalog?->name);
    }

    public function test_qualify_defaults_to_and_supports_full_depth(): void
    {
        $name = new QualifiedName(
            new Identifier('users'),
            new Identifier('public'),
            new Identifier('app'),
        );

        $qualified = $name->qualify();

        self::assertSame('users', $qualified->object->name);
        self::assertSame('public', $qualified->schema?->name);
        self::assertSame('app', $qualified->catalog?->name);
    }

    public function test_qualify_rejects_invalid_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QualifiedName(new Identifier('users')))->qualify(0);
    }
}
