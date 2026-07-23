<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;

interface ProcessManagerFactoryInterface
{
    public function create(ConnectionInterface $connection, ServerInspectorInterface $server, QueryExecutorInterface $executor): ProcessManagerInterface;
}
