<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class QualifiedNameTest extends TestCase
{
    public function testItStoresObjectSchemaAndCatalog(): void
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

    public function testQualifyReturnsAValueWithRequestedDepth(): void
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

    public function testQualifyRejectsInvalidDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QualifiedName(new Identifier('users')))->qualify(4);
    }
}
