<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

interface QueryHistoryInterface
{
    public function record(QueryHistoryEntry $entry): void;

    /** @return list<QueryHistoryEntry> */
    public function getRecent(string $database, int $limit = 100): array;

    public function clearDatabase(string $database): void;
}
