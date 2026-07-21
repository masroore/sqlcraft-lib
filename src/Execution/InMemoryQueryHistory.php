<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use InvalidArgumentException;
use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;

final class InMemoryQueryHistory implements QueryHistoryInterface
{
    /** @var array<string, list<QueryHistoryEntry>> */
    private array $entries = [];

    #[\Override]
    public function record(QueryHistoryEntry $entry): void
    {
        $this->entries[$entry->database][] = $entry;
    }

    /** @return list<QueryHistoryEntry> */
    #[\Override]
    public function getRecent(string $database, int $limit = 100): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('History limit must be >= 1.');
        }

        return array_slice(array_reverse($this->entries[$database] ?? []), 0, $limit);
    }

    #[\Override]
    public function clearDatabase(string $database): void
    {
        unset($this->entries[$database]);
    }
}
