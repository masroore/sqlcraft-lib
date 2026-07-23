<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

final class PostgreSQLProcessManager extends AbstractProcessManager
{
    #[\Override]
    protected function killSql(int $id): string
    {
        return 'SELECT pg_terminate_backend(?)';
    }

    #[\Override]
    protected function killParams(int $id): array
    {
        return [$id];
    }
}
