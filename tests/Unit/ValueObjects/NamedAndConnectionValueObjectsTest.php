<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\ValueObjects\Charset;
use SQLCraft\ValueObjects\Collation;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Engine;
use SQLCraft\ValueObjects\Privilege;
use SQLCraft\ValueObjects\ServerVersion;

final class NamedAndConnectionValueObjectsTest extends TestCase
{
    public function test_named_values_compare_without_case_and_render_their_names(): void
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

    public function test_charset_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Charset('');
    }

    public function test_charset_rejects_null_byte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Charset("safe\0unsafe");
    }

    public function test_collation_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Collation('');
    }

    public function test_collation_rejects_null_byte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Collation("safe\0unsafe");
    }

    public function test_engine_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Engine('');
    }

    public function test_engine_rejects_null_byte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Engine("safe\0unsafe");
    }

    public function test_privilege_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Privilege('');
    }

    public function test_privilege_rejects_null_byte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Privilege("safe\0unsafe");
    }

    public function test_server_version_parses_and_compares_versions(): void
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

    public function test_server_version_defaults_missing_patch_to_zero(): void
    {
        $version = new ServerVersion('PostgreSQL 16.2');

        self::assertSame(16, $version->major);
        self::assertSame(2, $version->minor);
        self::assertSame(0, $version->patch);
    }

    public function test_server_version_rejects_invalid_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerVersion('not-a-version');
    }

    public function test_server_version_compares_default_and_boundary_components(): void
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

    public function test_server_version_trims_input_before_parsing(): void
    {
        $version = new ServerVersion('  v1.2.3  ');

        self::assertSame('v1.2.3', $version->value);
    }

    public function test_connection_parameters_stores_connection_options(): void
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
        self::assertSame('mysql', $parameters->driver);
    }

    public function test_connection_parameters_defaults_driver_to_null(): void
    {
        $parameters = new ConnectionParameters(database: 'shop');

        self::assertNull($parameters->driver);
    }

    public function test_connection_parameters_allows_socket_only_connections(): void
    {
        $parameters = new ConnectionParameters(socket: '/var/run/mysql.sock');

        self::assertSame('/var/run/mysql.sock', $parameters->socket);
        self::assertNull($parameters->host);
    }

    public function test_connection_parameters_rejects_blank_host(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionParameters(host: '   ');
    }

    public function test_connection_parameters_rejects_null_bytes_in_host(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionParameters(host: "db\0internal");
    }

    public function test_connection_parameters_rejects_blank_socket(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionParameters(socket: '   ');
    }

    public function test_connection_parameters_rejects_null_bytes_in_socket(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionParameters(socket: "/tmp/db\0.sock");
    }

    public function test_connection_parameters_accepts_port_boundaries(): void
    {
        self::assertSame(1, (new ConnectionParameters(port: 1))->port);
        self::assertSame(65535, (new ConnectionParameters(port: 65535))->port);
    }

    public function test_connection_parameters_rejects_port_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConnectionParameters(port: 0);
    }

    public function test_connection_parameters_rejects_port_above_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConnectionParameters(port: 65536);
    }
}
