<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;

final class CallbackQueryHistory implements QueryHistoryInterface
{
    /** @var \Closure(QueryHistoryEntry): void */
    private readonly \Closure $recorder;

    /** @var \Closure(string, int): list<QueryHistoryEntry> */
    private readonly \Closure $reader;

    /** @var \Closure(string): void */
    private readonly \Closure $clearer;

    public function __construct(
        callable $recorder,
        ?callable $reader = null,
        ?callable $clearer = null,
    ) {
        $this->recorder = static function (QueryHistoryEntry $entry) use ($recorder): void {
            $recorder($entry);
        };
        $this->reader = $reader === null
            ? static fn (string $database, int $limit): array => []
            : static function (string $database, int $limit) use ($reader): array {
                /** @var list<QueryHistoryEntry> $entries */
                $entries = $reader($database, $limit);

                return $entries;
            };
        $this->clearer = $clearer === null
            ? static function (string $database): void {}
        : static function (string $database) use ($clearer): void {
            $clearer($database);
        };
    }

    #[\Override]
    public function record(QueryHistoryEntry $entry): void
    {
        ($this->recorder)($entry);
    }

    /** @return list<QueryHistoryEntry> */
    #[\Override]
    public function getRecent(string $database, int $limit = 100): array
    {
        return ($this->reader)($database, $limit);
    }

    #[\Override]
    public function clearDatabase(string $database): void
    {
        ($this->clearer)($database);
    }
}
