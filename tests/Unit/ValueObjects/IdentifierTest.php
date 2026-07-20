<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\Identifier;

final class IdentifierTest extends TestCase
{
    public function testItStoresAndRendersAnIdentifier(): void
    {
        $identifier = new Identifier('users');

        self::assertSame('users', $identifier->name);
        self::assertSame('users', (string) $identifier);
        self::assertTrue($identifier->equals(new Identifier('users')));
        self::assertFalse($identifier->equals(new Identifier('Users')));
    }

    public function testItRejectsEmptyNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Identifier('');
    }

    public function testItRejectsNullBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Identifier("users\0archive");
    }
}
