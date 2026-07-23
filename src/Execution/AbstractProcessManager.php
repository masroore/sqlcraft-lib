<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Collections\ProcessCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\ProcessManagerInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;
use SQLCraft\Exceptions\ExtensionConfigurationException;

abstract class AbstractProcessManager implements ProcessManagerInterface
{
    public function __construct(protected ConnectionInterface $connection, protected ServerInspectorInterface $server, protected QueryExecutorInterface $executor)
    {
    }

    #[\Override]
    public function list(): ProcessCollection
    {
        return $this->server->getProcessList($this->connection);
    }

    #[\Override]
    public function kill(string|int $processId): void
    {
        $id = is_int($processId) ? $processId : (ctype_digit($processId) ? (int) $processId : 0);
        if ($id < 1) {
            throw new ExtensionConfigurationException('Process id must be a positive integer.');
        }$this->executor->executeAdministrative($this->connection, $this->killSql($id), $this->killParams($id));
    }

    abstract protected function killSql(int $id): string;

    /** @return array<string|int,mixed> */
    protected function killParams(int $id): array
    {
        return [];
    }
}
