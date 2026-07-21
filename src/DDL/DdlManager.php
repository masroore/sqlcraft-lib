<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use SQLCraft\Exceptions\OperationCancelledException;
use SQLCraft\DDL\Sqlite\TableRecreationStrategy;

final readonly class DdlManager
{
    public function __construct(
        private QueryExecutorInterface $executor,
        private ?TableRecreationStrategy $sqliteRecreation = null,
        private ?SchemaEventDispatcherInterface $events = null,
    ) {
    }

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
            $this->executor->executeDdl($connection, $sql);
            $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->events?->afterDdlExecuted($connection, $sql, $objectName, $elapsedMs);
            $this->events?->schemaChanged($connection, 'DDL', $objectName, 'ALTER');
        }
    }

    private function objectName(DdlBuilderInterface $builder): string
    {
        return (new \ReflectionClass($builder))->getShortName();
    }
}
