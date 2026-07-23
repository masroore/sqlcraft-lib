<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Platform\MariaDbPlatform;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ServerVersion;

final class CapabilityMatrixRegressionTest extends TestCase
{
    public function test_my_sql_matrix_matches_the_authoritative_table(): void
    {
        self::assertSame([
            'always' => [
                Capability::Table, Capability::View, Capability::Columns, Capability::Indexes,
                Capability::ForeignKeys, Capability::Sql, Capability::Database, Capability::DropColumn,
                Capability::Dump, Capability::Comment, Capability::Charset, Capability::Collation,
                Capability::Status, Capability::Variables, Capability::Processlist, Capability::Kill,
                Capability::Privileges, Capability::Trigger, Capability::Routine, Capability::Procedure,
                Capability::Event, Capability::Copy, Capability::MoveColumn, Capability::InsertUpdate,
                Capability::Compression, Capability::Partitions,
                Capability::CrossTableSearch, Capability::BlobStreaming,
            ],
            'versioned' => [
                [Capability::GeneratedColumns, [5, 7, 0]],
                [Capability::DescendingIndexes, [8, 0, 0]],
                [Capability::CheckConstraints, [8, 0, 16]],
            ],
        ], $this->matrix(new MySQLPlatform()));
    }

    public function test_maria_db_matrix_matches_the_authoritative_table(): void
    {
        self::assertSame([
            'always' => [
                Capability::Table, Capability::View, Capability::Columns, Capability::Indexes,
                Capability::ForeignKeys, Capability::Sql, Capability::Database, Capability::DropColumn,
                Capability::Dump, Capability::Comment, Capability::Charset, Capability::Collation,
                Capability::Status, Capability::Variables, Capability::Processlist, Capability::Kill,
                Capability::Privileges, Capability::Trigger, Capability::Routine, Capability::Procedure,
                Capability::Event, Capability::Copy, Capability::MoveColumn, Capability::InsertUpdate,
                Capability::Compression, Capability::Partitions, Capability::CrossTableSearch,
                Capability::BlobStreaming, Capability::DescendingIndexes,
            ],
            'versioned' => [
                [Capability::GeneratedColumns, [5, 2, 0]],
                [Capability::CheckConstraints, [10, 2, 1]],
                [Capability::Sequence, [10, 3, 0]],
            ],
        ], $this->matrix(new MariaDbPlatform()));
    }

    public function test_postgre_sql_matrix_matches_the_authoritative_table(): void
    {
        self::assertSame([
            'always' => [
                Capability::Table, Capability::View, Capability::Columns, Capability::Indexes,
                Capability::ForeignKeys, Capability::Sql, Capability::Database, Capability::DropColumn,
                Capability::Dump, Capability::Comment, Capability::Collation, Capability::Processlist,
                Capability::Kill, Capability::Trigger, Capability::Routine, Capability::Sequence,
                Capability::Scheme, Capability::Type, Capability::CheckConstraints, Capability::PartialIndexes,
                Capability::DescendingIndexes, Capability::Partitions,
                Capability::CrossTableSearch, Capability::BlobStreaming,
            ],
            'versioned' => [
                [Capability::MaterializedView, [9, 3, 0]],
                [Capability::GeneratedColumns, [12, 0, 0]],
                [Capability::Procedure, [11, 0, 0]],
            ],
        ], $this->matrix(new PostgreSQLPlatform()));
    }

    public function test_sqlite_matrix_matches_the_authoritative_table(): void
    {
        self::assertSame([
            'always' => [
                Capability::Table, Capability::View, Capability::Columns, Capability::Indexes,
                Capability::ForeignKeys, Capability::Sql, Capability::Database, Capability::DropColumn,
                Capability::Dump, Capability::Status, Capability::Variables, Capability::Trigger,
                Capability::CheckConstraints, Capability::DescendingIndexes, Capability::PartialIndexes,
                Capability::InsertUpdate,
                Capability::CrossTableSearch, Capability::BlobStreaming,
            ],
            'versioned' => [[Capability::GeneratedColumns, [3, 31, 0]]],
        ], $this->matrix(new SqlitePlatform()));
    }

    public function test_flavor_branching_is_not_scattered_through_my_sql_dialect_methods(): void
    {
        $filename = (new ReflectionMethod(MySQLPlatform::class, 'buildCapabilityMatrix'))->getFileName();
        if ($filename === false) {
            self::fail('Unable to locate MySQL platform source.');
        }
        $source = file_get_contents($filename);
        self::assertIsString($source);
        self::assertStringNotContainsString('getFlavor() ===', $source);
        self::assertTrue((new MariaDbPlatform())->getCapabilitySet(new ServerVersion('10.3.0'))->has(Capability::Sequence));
    }

    /** @return array{always: list<Capability>, versioned: list<array{0: Capability, 1: array{0: int, 1: int, 2: int}}> } */
    private function matrix(object $platform): array
    {
        $method = new ReflectionMethod($platform, 'buildCapabilityMatrix');
        $method->setAccessible(true);
        /** @var array{always: list<Capability>, versioned: list<array{0: Capability, 1: array{0: int, 1: int, 2: int}}> } $matrix */
        $matrix = $method->invoke($platform);

        return $matrix;
    }
}
