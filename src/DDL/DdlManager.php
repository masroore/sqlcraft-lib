<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\DDL\Sqlite\TableRecreationStrategy;
use SQLCraft\Exceptions\OperationCancelledException;

final readonly class DdlManager
{
    public function __construct(
        private QueryExecutorInterface $executor,
        private ?TableRecreationStrategy $sqliteRecreation = null,
        private ?SchemaEventDispatcherInterface $events = null,
    ) {}

    /** @return list<string> */
    public function preview(ConnectionInterface $connection, DdlBuilderInterface $builder): array
    {
        return $builder->toSql($connection->getPlatform());
    }

    public function execute(ConnectionInterface $connection, DdlBuilderInterface $builder): void
    {
        if ($builder instanceof AlterTableBuilder && $this->sqliteRecreation instanceof TableRecreationStrategy && $connection->getPlatformName() === 'sqlite') {
            $this->sqliteRecreation->execute($connection, $builder);

            return;
        }

        $objectName = $this->objectName($builder);
        foreach ($this->preview($connection, $builder) as $sql) {
            $cancelReason = $this->events?->beforeSchemaChange($connection, 'DDL', $objectName, 'ALTER', $sql);
            if ($cancelReason === null) {
                $cancelReason = $this->events?->beforeDdlExecuted($connection, $sql, $objectName);
            }
            if ($cancelReason !== null) {
                throw new OperationCancelledException($cancelReason);
            }

            $startedAt = hrtime(true);
            $this->executor->executeDdl($connection, $sql, objectName: $objectName);
            $this->events?->afterDdlExecuted($connection, $sql, $objectName, (hrtime(true) - $startedAt) / 1_000_000);
            $this->events?->schemaChanged($connection, 'DDL', $objectName, 'ALTER');
        }
    }

    private function objectName(DdlBuilderInterface $builder): string
    {
        if ($builder instanceof ObjectNameAwareDdlBuilderInterface) {
            return $builder->getObjectName();
        }

        return (new \ReflectionClass($builder))->getShortName();
    }
}
