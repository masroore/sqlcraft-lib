<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Connection;

use SQLCraft\DTO\ExecutionResult;

interface PreparedStatementInterface
{
    /** @param array<string|int, mixed> $params */
    public function execute(array $params): ExecutionResult;

    /** @param array<string|int, mixed> $params */
    public function query(array $params): ResultInterface;

    public function close(): void;
}
