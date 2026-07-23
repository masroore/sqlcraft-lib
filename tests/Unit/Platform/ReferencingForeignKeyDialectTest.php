<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class ReferencingForeignKeyDialectTest extends TestCase
{
    public function test_my_sql_and_postgre_sql_provide_reverse_foreign_key_queries(): void
    {
        $table = new QualifiedName(new Identifier('teams'));

        self::assertStringContainsString('REFERENCED_TABLE_NAME', (new MySQLPlatform())->getReferencingForeignKeysSql($table));
        self::assertStringContainsString('ccu.table_name', (new PostgreSQLPlatform())->getReferencingForeignKeysSql($table));
    }

    public function test_sqlite_rejects_reverse_foreign_key_inspection_explicitly(): void
    {
        try {
            (new SqlitePlatform())->getReferencingForeignKeysSql(new QualifiedName(new Identifier('teams')));
            self::fail('Expected reverse foreign-key capability exception.');
        } catch (CapabilityNotSupportedException $exception) {
            self::assertSame(Capability::ForeignKeys, $exception->capability);
            self::assertSame('sqlite', $exception->platform);
        }
    }
}
