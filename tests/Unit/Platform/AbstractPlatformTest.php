<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Platform\AbstractPlatform;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\ServerVersion;

final class AbstractPlatformTest extends TestCase
{
    public function testItProvidesStandardIdentifierAndSingleRowRendering(): void
    {
        $platform = new class () extends AbstractPlatform {
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
            public function getServerVersion(\SQLCraft\Contracts\Connection\ConnectionInterface $connection): ServerVersion
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
            public function convertFieldIn(\SQLCraft\DTO\ColumnMeta $column, string $expression): string
            {
                return $expression;
            }
            #[\Override]
            public function convertFieldOut(\SQLCraft\DTO\ColumnMeta $column, string $expression): string
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
            public function renderColumnDefinition(\SQLCraft\DTO\ColumnMeta $column): string
            {
                return '';
            }
            #[\Override]
            public function renderPrimaryKeyClause(\SQLCraft\DTO\IndexMeta $index): string
            {
                return '';
            }
            #[\Override]
            public function renderForeignKeyClause(\SQLCraft\DTO\ForeignKeyMeta $foreignKey): string
            {
                return '';
            }
            #[\Override]
            public function renderCheckConstraintClause(\SQLCraft\DTO\CheckConstraintMeta $check): string
            {
                return '';
            }
            #[\Override]
            public function renderCreateTableStatement(\SQLCraft\ValueObjects\QualifiedName $table, array $columnClauses, array $constraintClauses, array $tableOptions): string
            {
                return '';
            }
            #[\Override]
            public function renderAlterTableAddColumn(\SQLCraft\ValueObjects\QualifiedName $table, \SQLCraft\DTO\ColumnMeta $column): string
            {
                return '';
            }
            #[\Override]
            public function renderAlterTableDropColumn(\SQLCraft\ValueObjects\QualifiedName $table, Identifier $column): string
            {
                return '';
            }
            #[\Override]
            public function renderCreateIndexStatement(\SQLCraft\ValueObjects\QualifiedName $table, \SQLCraft\DTO\IndexMeta $index): string
            {
                return '';
            }
            #[\Override]
            public function renderDropIndexStatement(\SQLCraft\ValueObjects\QualifiedName $table, Identifier $indexName): string
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
            public function getColumnsSql(\SQLCraft\ValueObjects\QualifiedName $table): string
            {
                return '';
            }
            #[\Override]
            public function getTableStatusSql(\SQLCraft\ValueObjects\QualifiedName $table): string
            {
                return '';
            }
            #[\Override]
            public function getParentTablesSql(\SQLCraft\ValueObjects\QualifiedName $table): string
            {
                return '';
            }
            #[\Override]
            public function getPartitionsSql(\SQLCraft\ValueObjects\QualifiedName $table): string
            {
                return '';
            }
            #[\Override]
            public function getViewsSql(?string $schema = null): string
            {
                return '';
            }
            #[\Override]
            public function getViewDefinitionSql(\SQLCraft\ValueObjects\QualifiedName $view): string
            {
                return '';
            }
            #[\Override]
            public function getMaterializedViewsSql(?string $schema = null): string
            {
                return '';
            }
            #[\Override]
            public function getIndexesSql(\SQLCraft\ValueObjects\QualifiedName $table): string
            {
                return '';
            }
            #[\Override]
            public function getForeignKeysSql(\SQLCraft\ValueObjects\QualifiedName $table): string
            {
                return '';
            }
            #[\Override]
            public function getReferencingForeignKeysSql(\SQLCraft\ValueObjects\QualifiedName $table): string
            {
                return '';
            }
            #[\Override]
            public function getTriggersSql(\SQLCraft\ValueObjects\QualifiedName $table): string
            {
                return '';
            }
            #[\Override]
            public function getRoutinesSql(?string $schema = null): string
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
