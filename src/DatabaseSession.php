<?php

declare(strict_types=1);

namespace SQLCraft;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Query\PaginatorInterface;
use SQLCraft\Contracts\Schema\SchemaManagerInterface;
use SQLCraft\Contracts\Security\SecurityGuardInterface;
use SQLCraft\Contracts\Security\UserManagerInterface;
use SQLCraft\Contracts\Security\PrivilegeManagerInterface;
use SQLCraft\DDL\DdlManager;
use SQLCraft\Export\Exporter;
use SQLCraft\Import\Importer;
use SQLCraft\Query\Page;
use SQLCraft\Query\InsertQuery;
use SQLCraft\Query\UpdateQuery;
use SQLCraft\Query\DeleteQuery;
use SQLCraft\Query\InsertQueryRenderer;
use SQLCraft\Query\UpdateQueryRenderer;
use SQLCraft\Query\DeleteQueryRenderer;
use SQLCraft\Query\PaginationParams;
use SQLCraft\Query\SelectQuery;

final readonly class DatabaseSession
{
    public function __construct(
        private ConnectionInterface $connection,
        private SchemaManagerInterface $schema,
        private DdlManager $ddl,
        private QueryExecutorInterface $executor,
        private Exporter $exporter,
        private Importer $importer,
        private SecurityGuardInterface $security,
        private UserManagerInterface $users,
        private PrivilegeManagerInterface $privileges,
    ) {
    }

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    /** @param array<string|int, mixed> $params */
    public function query(string $sql, array $params = []): \SQLCraft\Contracts\Connection\ResultInterface
    {
        return $this->executor->query($this->connection, $sql, $params);
    }

    public function executeBuilder(InsertQuery|UpdateQuery|DeleteQuery $query): \SQLCraft\DTO\ExecutionResult
    {
        $rendered = match (true) {
            $query instanceof InsertQuery => (new InsertQueryRenderer($this->connection->getPlatform()))->render($query),
            $query instanceof UpdateQuery => (new UpdateQueryRenderer($this->connection->getPlatform()))->render($query),
            default => (new DeleteQueryRenderer($this->connection->getPlatform()))->render($query),
        };

        return $this->executor->execute($this->connection, $rendered['sql'], $rendered['params']);
    }

    public function schema(): SchemaManagerInterface
    {
        return $this->schema;
    }

    public function ddl(): DdlManager
    {
        return $this->ddl;
    }

    public function security(): SecurityGuardInterface
    {
        return $this->security;
    }

    public function users(): UserManagerInterface
    {
        return $this->users;
    }

    public function privileges(): PrivilegeManagerInterface
    {
        return $this->privileges;
    }

    public function export(): Exporter
    {
        return $this->exporter;
    }

    public function import(): Importer
    {
        return $this->importer;
    }
}
