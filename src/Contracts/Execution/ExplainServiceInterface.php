<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ExplainResult;

interface ExplainServiceInterface
{
    /** @param array<string|int, mixed> $params */
    public function explain(
        ConnectionInterface $connection,
        string $sql,
        array $params = [],
        bool $analyze = false,
    ): ExplainResult;
}
