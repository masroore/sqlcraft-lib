<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Execution\TransactionManagerInterface;

final class ExecutionContractsTest extends TestCase
{
    public function testQueryExecutorPortExposesTheThreeExecutionModes(): void
    {
        self::assertSame(
            ['execute', 'query', 'executeDdl'],
            $this->methodNames(QueryExecutorInterface::class),
        );
    }

    public function testTransactionManagerPortExposesNestedTransactionOperations(): void
    {
        self::assertSame(
            ['begin', 'transactional'],
            $this->methodNames(TransactionManagerInterface::class),
        );
    }

    /**
     * @param class-string $interface
     * @return list<string>
     */
    private function methodNames(string $interface): array
    {
        return array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($interface))->getMethods(),
        );
    }
}
