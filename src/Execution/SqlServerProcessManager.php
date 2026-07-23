<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

final class SqlServerProcessManager extends AbstractProcessManager
{
    #[\Override]
    protected function killSql(int $id): string
    {
        return sprintf('KILL %d', $id);
    }

    #[\Override]
    protected function killParams(int $id): array
    {
        return [];
    }
}
