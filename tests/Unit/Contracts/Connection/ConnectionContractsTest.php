<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PreparedStatementInterface;
use SQLCraft\Contracts\Connection\ResultInterface;

final class ConnectionContractsTest extends TestCase
{
    public function testConnectionInterfaceExposesThePlannedBoundary(): void
    {
        self::assertSame(
            [
                'getPlatformName',
                'getServerVersion',
                'getPlatform',
                'getName',
                'getDatabaseName',
                'execute',
                'query',
                'prepare',
                'quoteIdentifier',
                'quoteValue',
                'lastInsertId',
                'affectedRows',
                'beginTransaction',
                'inTransaction',
                'ping',
                'isConnected',
                'close',
            ],
            $this->methodNames(ConnectionInterface::class),
        );
    }

    public function testResultAndPreparedStatementContractsArePublicPorts(): void
    {
        self::assertTrue((new \ReflectionClass(ResultInterface::class))->isInterface());
        self::assertTrue((new \ReflectionClass(PreparedStatementInterface::class))->isInterface());
        self::assertSame(['fetchAssoc', 'fetchRow', 'fetchAll', 'fetchColumn', 'getColumns', 'seek', 'isStreaming', 'count', 'getIterator'], $this->methodNames(ResultInterface::class));
        self::assertSame(['execute', 'query', 'close'], $this->methodNames(PreparedStatementInterface::class));
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
