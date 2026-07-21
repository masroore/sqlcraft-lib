<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\DTO\ExecutionResult;

interface QueryExecutorInterface
{
    /** @param array<string|int, mixed> $params */
    public function execute(
        ConnectionInterface $connection,
        string $sql,
        array $params = [],
    ): ExecutionResult;

    /** @param array<string|int, mixed> $params */
    public function query(
        ConnectionInterface $connection,
        string $sql,
        array $params = [],
        bool $buffered = false,
    ): ResultInterface;

    /** @param array<string|int, mixed> $params */
    public function executeDdl(
        ConnectionInterface $connection,
        string $sql,
        array $params = [],
        string $objectName = '',
    ): void;

    /** @param array<string|int, mixed> $params */
    public function queryWithTimeout(
        ConnectionInterface $connection,
        string $sql,
        array $params = [],
        int $timeoutMs = 0,
    ): ?ResultInterface;
}
