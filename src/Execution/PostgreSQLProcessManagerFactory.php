<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\ProcessManagerFactoryInterface;
use SQLCraft\Contracts\Execution\ProcessManagerInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;

final class PostgreSQLProcessManagerFactory implements ProcessManagerFactoryInterface
{
    #[\Override]
    public function create(ConnectionInterface $connection, ServerInspectorInterface $server, QueryExecutorInterface $executor): ProcessManagerInterface
    {
        return new PostgreSQLProcessManager($connection, $server, $executor);
    }
}
