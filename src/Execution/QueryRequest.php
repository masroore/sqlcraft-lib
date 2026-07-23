<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Enums\QueryKind;

final readonly class QueryRequest
{
    /** @param array<string|int, mixed> $params */
    public function __construct(public ConnectionInterface $connection, public string $originalSql, public string $sql, public array $params, public QueryKind $kind)
    {
    }

    /** @param array<string|int, mixed> $params */
    public function withSqlAndParams(string $sql, array $params): self
    {
        return new self($this->connection, $this->originalSql, $sql, $params, $this->kind);
    }
}
