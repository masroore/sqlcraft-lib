<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\Platform\AbstractPlatform;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\ServerVersion;

final class AbstractPlatformTest extends TestCase
{
    public function test_it_provides_standard_identifier_and_single_row_rendering(): void
    {
        $platform = new class extends AbstractPlatform
        {
            #[\Override]
            public function getName(): string
            {
                return 'test';
            }

            #[\Override]
            public function getFlavor(): ?string
            {
                return null;
            }

            #[\Override]
            public function getServerVersion(ConnectionInterface $connection): ServerVersion
            {
                return new ServerVersion('1.0.0');
            }

            #[\Override]
            public function getDefaultCharset(): ?string
            {
                return null;
            }

            #[\Override]
            public function getDefaultCollation(): ?string
            {
                return null;
            }

            #[\Override]
            public function supportsSchemas(): bool
            {
                return false;
            }

            #[\Override]
            public function getKeywordList(): array
            {
                return [];
            }

            #[\Override]
            public function quoteValue(mixed $value): string
            {
                return '';
            }

            #[\Override]
            public function quoteBinary(string $bytes): string
            {
                return '';
            }

            #[\Override]
            public function convertFieldIn(ColumnMeta $column, string $expression): string
            {
                return $expression;
            }

            #[\Override]
            public function convertFieldOut(ColumnMeta $column, string $expression): string
            {
                return $expression;
            }

            #[\Override]
            public function applyPagination(string $sql, int $limit, int $offset): string
            {
                return $sql;
            }

            #[\Override]
            public function mapPhpTypeToDb(string $phpType): string
            {
                return 'TEXT';
            }

            #[\Override]
            public function getSupportedTypes(): array
            {
                return [];
            }

            #[\Override]
            public function getUnsignedTypes(): array
            {
                return [];
            }

            #[\Override]
            public function getCollatableTypes(): array
            {
                return [];
            }

            #[\Override]
            public function renderColumnDefinition(ColumnMeta $column): string
            {
                return '';
            }

            #[\Override]
            public function renderPrimaryKeyClause(IndexMeta $index): string
            {
                return '';
            }

            #[\Override]
            public function renderForeignKeyClause(ForeignKeyMeta $foreignKey): string
            {
                return '';
            }

            #[\Override]
            public function renderCheckConstraintClause(CheckConstraintMeta $check): string
            {
                return '';
            }

            #[\Override]
            public function renderCreateTableStatement(QualifiedName $table, array $columnClauses, array $constraintClauses, array $tableOptions): string
            {
                return '';
            }

            #[\Override]
            public function renderAlterTableAddColumn(QualifiedName $table, ColumnMeta $column): string
            {
                return '';
            }

            #[\Override]
            public function renderAlterTableDropColumn(QualifiedName $table, Identifier $column): string
            {
                return '';
            }

            #[\Override]
            public function renderCreateIndexStatement(QualifiedName $table, IndexMeta $index): string
            {
                return '';
            }

            #[\Override]
            public function renderDropIndexStatement(QualifiedName $table, Identifier $indexName): string
            {
                return '';
            }

            #[\Override]
            public function getDatabasesSql(): string
            {
                return '';
            }

            #[\Override]
            public function getSchemasSql(): string
            {
                return '';
            }

            #[\Override]
            public function getTypesSql(?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getTablesSql(string $database, ?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getColumnsSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getAllColumnsSql(string $database, ?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getAllIndexesSql(string $database, ?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getAllForeignKeysSql(string $database, ?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getTableStatusSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getParentTablesSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getPartitionsSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getViewsSql(?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getViewDefinitionSql(QualifiedName $view): string
            {
                return '';
            }

            #[\Override]
            public function getMaterializedViewsSql(?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getIndexesSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getForeignKeysSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getReferencingForeignKeysSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getTriggersSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getRoutinesSql(?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getRoutineDetailSql(QualifiedName $routine): string
            {
                return '';
            }

            #[\Override]
            public function getCheckConstraintsSql(QualifiedName $table): string
            {
                return '';
            }

            #[\Override]
            public function getUsersSql(): string
            {
                return '';
            }

            #[\Override]
            public function getSequencesSql(?string $schema = null): string
            {
                return '';
            }

            #[\Override]
            public function getVariablesSql(): string
            {
                return '';
            }

            #[\Override]
            public function getStatusSql(): string
            {
                return '';
            }

            #[\Override]
            public function getCharsetsSql(): string
            {
                return '';
            }

            #[\Override]
            public function getCollationsSql(?string $charset = null): string
            {
                return '';
            }

            #[\Override]
            public function getProcesslistSql(): string
            {
                return '';
            }

            /** @return array{always: list<Capability>, versioned: list<array{0: Capability, 1: array{0: int, 1: int, 2: int}}> } */
            #[\Override]
            protected function buildCapabilityMatrix(): array
            {
                return ['always' => [Capability::Table], 'versioned' => []];
            }
        };

        self::assertSame('"a""b"', $platform->quoteIdentifier(new Identifier('a"b')));
        self::assertSame('SELECT 1 LIMIT 1', $platform->applySingleRowLimit('SELECT 1', ''));
        self::assertTrue($platform->getCapabilitySet(new ServerVersion('1.0.0'))->has(Capability::Table));
    }
}
