<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;

final class NullQueryHistory implements QueryHistoryInterface
{
    #[\Override]
    public function record(QueryHistoryEntry $entry): void
    {
    }

    /** @return list<QueryHistoryEntry> */
    #[\Override]
    public function getRecent(string $database, int $limit = 100): array
    {
        return [];
    }

    #[\Override]
    public function clearDatabase(string $database): void
    {
    }
}
