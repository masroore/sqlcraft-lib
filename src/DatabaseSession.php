<?php

declare(strict_types=1);

namespace SQLCraft;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Schema\SchemaManagerInterface;
use SQLCraft\Contracts\Security\PrivilegeManagerInterface;
use SQLCraft\Contracts\Security\SecurityGuardInterface;
use SQLCraft\Contracts\Security\UserManagerInterface;
use SQLCraft\DDL\DdlManager;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Export\Exporter;
use SQLCraft\Export\FormatRegistry;
use SQLCraft\Import\CsvImporter;
use SQLCraft\Contracts\Import\CsvImporterInterface;
use SQLCraft\Contracts\Execution\ProcessManagerInterface;
use SQLCraft\Schema\SchemaManager;
use SQLCraft\Import\Importer;
use SQLCraft\Query\DeleteQuery;
use SQLCraft\Query\DeleteQueryRenderer;
use SQLCraft\Query\InsertQuery;
use SQLCraft\Query\InsertQueryRenderer;
use SQLCraft\Query\UpdateQuery;
use SQLCraft\Query\UpdateQueryRenderer;

final readonly class DatabaseSession
{
    public function __construct(
        private ConnectionInterface $connection,
        private SchemaManager $schema,
        private DdlManager $ddl,
        private QueryExecutorInterface $executor,
        private Exporter $exporter,
        private Importer $importer,
        private SecurityGuardInterface $security,
        private UserManagerInterface $users,
        private PrivilegeManagerInterface $privileges,
        private ?FormatRegistry $formats = null,
        private ?CsvImporterInterface $csvImport = null,
        private ?ProcessManagerInterface $processes = null,
    ) {}

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    /** @param array<string|int, mixed> $params */
    public function query(string $sql, array $params = []): ResultInterface
    {
        return $this->executor->query($this->connection, $sql, $params);
    }

    public function executeBuilder(InsertQuery|UpdateQuery|DeleteQuery $query): ExecutionResult
    {
        $rendered = match (true) {
            $query instanceof InsertQuery => (new InsertQueryRenderer($this->connection->getPlatform()))->render($query),
            $query instanceof UpdateQuery => (new UpdateQueryRenderer($this->connection->getPlatform()))->render($query),
            default => (new DeleteQueryRenderer($this->connection->getPlatform()))->render($query),
        };

        return $this->executor->execute($this->connection, $rendered['sql'], $rendered['params']);
    }

    public function schema(): SchemaManager
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

    public function formats(): FormatRegistry
    {
        return $this->formats ?? throw new \LogicException('Formats are not configured.');
    }

    public function csvImport(): CsvImporterInterface
    {
        return $this->csvImport ?? throw new \LogicException('CSV import is not configured.');
    }

    public function processes(): ProcessManagerInterface
    {
        return $this->processes ?? throw \SQLCraft\Capabilities\CapabilityNotSupportedException::for(Capability::Kill, $this->connection->getPlatformName());
    }
}
