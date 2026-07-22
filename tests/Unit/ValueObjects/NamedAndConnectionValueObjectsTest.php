<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\Charset;
use SQLCraft\ValueObjects\Collation;
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Engine;
use SQLCraft\ValueObjects\Privilege;
use SQLCraft\ValueObjects\ServerVersion;

final class NamedAndConnectionValueObjectsTest extends TestCase
{
    public function testNamedValuesCompareWithoutCaseAndRenderTheirNames(): void
    {
        $charset = new Charset('utf8mb4');
        $collation = new Collation('utf8mb4_unicode_ci');
        $engine = new Engine('InnoDB');
        $privilege = new Privilege(' select ');

        self::assertTrue($charset->equals(new Charset('UTF8MB4')));
        self::assertSame('utf8mb4', (string) $charset);
        self::assertTrue($collation->equals(new Collation('UTF8MB4_UNICODE_CI')));
        self::assertSame('utf8mb4_unicode_ci', (string) $collation);
        self::assertTrue($engine->equals(new Engine('innodb')));
        self::assertSame('InnoDB', (string) $engine);
        self::assertSame('SELECT', $privilege->name);
        self::assertSame('SELECT', (string) $privilege);
        self::assertTrue($privilege->equals(new Privilege('SELECT')));
    }

    public function testCharsetRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Charset('');
    }

    public function testCharsetRejectsNullByte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Charset("safe\0unsafe");
    }

    public function testCollationRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Collation('');
    }

    public function testCollationRejectsNullByte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Collation("safe\0unsafe");
    }

    public function testEngineRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Engine('');
    }

    public function testEngineRejectsNullByte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Engine("safe\0unsafe");
    }

    public function testPrivilegeRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Privilege('');
    }

    public function testPrivilegeRejectsNullByte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Privilege("safe\0unsafe");
    }

    public function testServerVersionParsesAndComparesVersions(): void
    {
        $version = new ServerVersion('MySQL 8.0.36-0ubuntu0');

        self::assertSame('MySQL 8.0.36-0ubuntu0', $version->value);
        self::assertSame(8, $version->major);
        self::assertSame(0, $version->minor);
        self::assertSame(36, $version->patch);
        self::assertTrue($version->isAtLeast(8, 0, 30));
        self::assertFalse($version->isAtLeast(8, 1));
        self::assertSame(0, $version->compare(8, 0, 36));
        self::assertTrue($version->equals(new ServerVersion('8.0.36')));
        self::assertSame('MySQL 8.0.36-0ubuntu0', (string) $version);
    }

    public function testServerVersionDefaultsMissingPatchToZero(): void
    {
        $version = new ServerVersion('PostgreSQL 16.2');

        self::assertSame(16, $version->major);
        self::assertSame(2, $version->minor);
        self::assertSame(0, $version->patch);
    }

    public function testServerVersionRejectsInvalidValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerVersion('not-a-version');
    }

    public function testServerVersionComparesDefaultAndBoundaryComponents(): void
    {
        $version = new ServerVersion('8.0.36');
        $zero = new ServerVersion('8.0.0');

        self::assertTrue($version->isAtLeast(8, 0, 36));
        self::assertFalse($version->isAtLeast(8, 0, 37));
        self::assertSame(1, $version->compare(8, 0, 35));
        self::assertSame(-1, $version->compare(8, 0, 37));
        self::assertTrue($zero->isAtLeast(8));
        self::assertSame(0, $zero->compare(8));
        self::assertFalse($version->equals(new ServerVersion('8.1.36')));
        self::assertFalse($version->equals(new ServerVersion('8.0.37')));
    }

    public function testServerVersionTrimsInputBeforeParsing(): void
    {
        $version = new ServerVersion('  v1.2.3  ');

        self::assertSame('v1.2.3', $version->value);
    }

    public function testConnectionParametersStoresConnectionOptions(): void
    {
        $parameters = new ConnectionParameters(
            host: 'db.internal',
            port: 3306,
            database: 'shop',
            username: 'app',
            password: 'secret',
            charset: 'utf8mb4',
            ssl: ['verifyPeer' => true],
            extras: ['applicationName' => 'sqlcraft'],
            driver: DatabaseDriver::MySQL,
        );

        self::assertSame('db.internal', $parameters->host);
        self::assertSame(3306, $parameters->port);
        self::assertSame('shop', $parameters->database);
        self::assertSame('app', $parameters->username);
        self::assertSame('secret', $parameters->password);
        self::assertSame('utf8mb4', $parameters->charset);
        self::assertSame(['verifyPeer' => true], $parameters->ssl);
        self::assertSame(['applicationName' => 'sqlcraft'], $parameters->extras);
        self::assertSame(DatabaseDriver::MySQL, $parameters->driver);
    }

    public function testConnectionParametersDefaultsDriverToNull(): void
    {
        $parameters = new ConnectionParameters(database: 'shop');

        self::assertNull($parameters->driver);
    }

    public function testConnectionParametersAllowsSocketOnlyConnections(): void
    {
        $parameters = new ConnectionParameters(socket: '/var/run/mysql.sock');

        self::assertSame('/var/run/mysql.sock', $parameters->socket);
        self::assertNull($parameters->host);
    }

    public function testConnectionParametersRejectsBlankHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionParameters(host: '   ');
    }

    public function testConnectionParametersRejectsNullBytesInHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionParameters(host: "db\0internal");
    }

    public function testConnectionParametersRejectsBlankSocket(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionParameters(socket: '   ');
    }

    public function testConnectionParametersRejectsNullBytesInSocket(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionParameters(socket: "/tmp/db\0.sock");
    }

    public function testConnectionParametersAcceptsPortBoundaries(): void
    {
        self::assertSame(1, (new ConnectionParameters(port: 1))->port);
        self::assertSame(65535, (new ConnectionParameters(port: 65535))->port);
    }

    public function testConnectionParametersRejectsPortZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConnectionParameters(port: 0);
    }

    public function testConnectionParametersRejectsPortAboveMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConnectionParameters(port: 65536);
    }
}
