<?php

declare(strict_types=1);

namespace SQLCraft;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Query\PaginatorInterface;
use SQLCraft\Contracts\Schema\SchemaManagerInterface;
use SQLCraft\Contracts\Security\SecurityGuardInterface;
use SQLCraft\DDL\DdlManager;
use SQLCraft\Export\Exporter;
use SQLCraft\Import\Importer;
use SQLCraft\Query\Page;
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

    public function export(): Exporter
    {
        return $this->exporter;
    }

    public function import(): Importer
    {
        return $this->importer;
    }
}
