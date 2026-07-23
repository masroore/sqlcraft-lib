<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Platform;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PaginationInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\Contracts\Platform\TypeMapperInterface;

final class PlatformContractsTest extends TestCase
{
    public function test_segregated_platform_ports_expose_the_planned_methods(): void
    {
        self::assertSame(['quoteIdentifier', 'quoteValue', 'quoteBinary', 'convertFieldIn', 'convertFieldOut'], $this->methods(QuotingInterface::class));
        self::assertSame(['applyPagination', 'applySingleRowLimit'], $this->methods(PaginationInterface::class));
        self::assertSame(['mapPhpTypeToDb', 'getSupportedTypes', 'getUnsignedTypes', 'getCollatableTypes'], $this->methods(TypeMapperInterface::class));
        self::assertSame(['getDatabasesSql', 'getSchemasSql', 'getTypesSql', 'getTablesSql', 'getColumnsSql', 'getAllColumnsSql', 'getAllIndexesSql', 'getAllForeignKeysSql', 'getTableStatusSql', 'getViewsSql', 'getViewDefinitionSql', 'getMaterializedViewsSql', 'getParentTablesSql', 'getPartitionsSql', 'getIndexesSql', 'getForeignKeysSql', 'getReferencingForeignKeysSql', 'getTriggersSql', 'getRoutinesSql', 'getRoutineDetailSql', 'getCheckConstraintsSql', 'getUsersSql', 'getSequencesSql', 'getVariablesSql', 'getStatusSql', 'getCharsetsSql', 'getCollationsSql', 'getProcesslistSql'], $this->methods(IntrospectionDialectInterface::class));
        self::assertSame(['renderDdlAlterTable', 'renderCreateViewStatement', 'renderDropViewStatement', 'renderTruncateStatement', 'renderCreateTriggerStatement', 'renderDropTriggerStatement', 'renderCreateRoutineStatement', 'renderDropRoutineStatement', 'renderCreateSequenceStatement', 'renderDropSequenceStatement', 'renderCreateDatabaseStatement', 'renderAlterDatabaseStatement', 'renderCopyTableStatement', 'renderAlterViewStatement', 'renderAlterRoutineStatement', 'renderDropDatabaseStatement', 'renderCreateSchemaStatement', 'renderDropSchemaStatement', 'renderUseDatabaseStatement', 'renderDdlColumnDefinition', 'renderDdlPrimaryKeyClause', 'renderDdlForeignKeyClause', 'renderDdlCheckConstraintClause', 'renderColumnDefinition', 'renderPrimaryKeyClause', 'renderForeignKeyClause', 'renderCheckConstraintClause', 'renderCreateTableStatement', 'renderDropTableStatement', 'renderAlterTableAddColumn', 'renderAlterTableDropColumn', 'renderCreateIndexStatement', 'renderDdlCreateIndexStatement', 'renderDropIndexStatement'], $this->methods(DdlDialectInterface::class));
    }

    public function test_composite_platform_port_includes_all_dialect_methods(): void
    {
        $reflection = new \ReflectionClass(PlatformInterface::class);
        $methods = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $reflection->getMethods());

        self::assertTrue($reflection->isInterface());
        foreach (['getName', 'getFlavor', 'getServerVersion', 'getCapabilitySet', 'getDefaultCharset', 'getDefaultCollation', 'supportsSchemas', 'ddl', 'introspection', 'queryDialect', 'quoting', 'types'] as $method) {
            self::assertContains($method, $methods);
        }
    }

    /**
     * @param  class-string  $interface
     * @return list<string>
     */
    private function methods(string $interface): array
    {
        return array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($interface))->getMethods(),
        );
    }
}
