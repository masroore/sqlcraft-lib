<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Exceptions\InvalidOperatorException;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Security\IdentifierQuoter;
use SQLCraft\Security\OperatorValidator;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class SecurityUtilitiesTest extends TestCase
{
    public function test_identifier_quoter_delegates_single_and_qualified_names(): void
    {
        $quoter = new IdentifierQuoter(new MySQLPlatform);
        $name = new QualifiedName(new Identifier('table'), new Identifier('schema'), new Identifier('database'));

        self::assertSame('`column`', $quoter->quote(new Identifier('column')));
        self::assertSame('`database`.`schema`.`table`', $quoter->quoteQualified($name));
        self::assertSame('`table`', $quoter->quoteQualified(new QualifiedName(new Identifier('table'))));
    }

    public function test_operator_validator_normalizes_allowed_operators(): void
    {
        $validator = new OperatorValidator(new MySQLPlatform);

        self::assertSame('NOT LIKE', $validator->validate(' not like '));
    }

    public function test_operator_validator_rejects_structural_injection(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getName')->willReturn('test');
        $platform->method('getOperators')->willReturn(['=', 'LIKE']);
        $validator = new OperatorValidator($platform);

        $this->expectException(InvalidOperatorException::class);
        $validator->validate('LIKE; DROP TABLE users');
    }
}
