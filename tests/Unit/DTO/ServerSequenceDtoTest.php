<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\SequenceMeta;
use SQLCraft\DTO\ServerInfo;
use SQLCraft\ValueObjects\ServerVersion;

final class ServerSequenceDtoTest extends TestCase
{
    public function test_server_info_stores_version_and_nullable_metadata(): void
    {
        $server = new ServerInfo(
            version: new ServerVersion('PostgreSQL 18.0'),
            platformName: 'PostgreSQL',
            flavor: null,
            dataDirectory: '/var/lib/postgresql/data',
            timezone: 'UTC',
            charset: 'UTF8',
            collation: null,
        );

        self::assertSame('PostgreSQL 18.0', $server->version->value);
        self::assertSame('PostgreSQL', $server->platformName);
        self::assertNull($server->flavor);
        self::assertSame('/var/lib/postgresql/data', $server->dataDirectory);
        self::assertSame('UTC', $server->timezone);
        self::assertSame('UTF8', $server->charset);
        self::assertNull($server->collation);
    }

    public function test_server_info_can_be_constructed_with_no_optional_metadata(): void
    {
        $server = new ServerInfo(
            version: new ServerVersion('8.4.23'),
            platformName: 'SQLite',
            flavor: null,
            dataDirectory: null,
            timezone: null,
            charset: null,
            collation: null,
        );

        self::assertSame(8, $server->version->major);
        self::assertSame(4, $server->version->minor);
        self::assertSame(23, $server->version->patch);
        self::assertNull($server->dataDirectory);
        self::assertNull($server->timezone);
        self::assertNull($server->charset);
        self::assertNull($server->collation);
    }

    public function test_sequence_meta_stores_integer_values_and_ownership(): void
    {
        $sequence = new SequenceMeta(
            name: 'users_id_seq',
            schema: 'public',
            startValue: 1,
            minValue: 1,
            maxValue: 2_147_483_647,
            increment: 1,
            cycle: false,
            ownedByTable: 'users',
            ownedByColumn: 'id',
        );

        self::assertSame('users_id_seq', $sequence->name);
        self::assertSame('public', $sequence->schema);
        self::assertSame(1, $sequence->startValue);
        self::assertSame(1, $sequence->minValue);
        self::assertSame(2_147_483_647, $sequence->maxValue);
        self::assertSame(1, $sequence->increment);
        self::assertFalse($sequence->cycle);
        self::assertSame('users', $sequence->ownedByTable);
        self::assertSame('id', $sequence->ownedByColumn);
    }

    public function test_sequence_meta_supports_string_numeric_values_and_unowned_cycled_sequences(): void
    {
        $sequence = new SequenceMeta(
            name: 'event_number_seq',
            schema: null,
            startValue: '9223372036854775808',
            minValue: '-9223372036854775808',
            maxValue: '18446744073709551615',
            increment: 10,
            cycle: true,
            ownedByTable: null,
            ownedByColumn: null,
        );

        self::assertSame('9223372036854775808', $sequence->startValue);
        self::assertSame('-9223372036854775808', $sequence->minValue);
        self::assertSame('18446744073709551615', $sequence->maxValue);
        self::assertSame(10, $sequence->increment);
        self::assertTrue($sequence->cycle);
        self::assertNull($sequence->schema);
        self::assertNull($sequence->ownedByTable);
        self::assertNull($sequence->ownedByColumn);
    }
}
