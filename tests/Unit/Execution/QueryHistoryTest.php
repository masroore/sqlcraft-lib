<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Execution\CallbackQueryHistory;
use SQLCraft\Execution\InMemoryQueryHistory;
use SQLCraft\Execution\NullQueryHistory;

final class QueryHistoryTest extends TestCase
{
    public function testInMemoryHistoryReturnsNewestEntriesFirstAndClearsDatabase(): void
    {
        $history = new InMemoryQueryHistory();
        $first = $this->entry('SELECT 1');
        $second = $this->entry('SELECT 2');
        $history->record($first);
        $history->record($second);

        self::assertSame([$second, $first], $history->getRecent('app'));
        $history->clearDatabase('app');
        self::assertSame([], $history->getRecent('app'));
    }

    public function testNullHistoryIsNoOp(): void
    {
        $history = new NullQueryHistory();
        $history->record($this->entry('SELECT 1'));

        self::assertSame([], $history->getRecent('app'));
    }

    public function testCallbackHistoryDelegatesStorageOperations(): void
    {
        $recorded = [];
        $cleared = [];
        $history = new CallbackQueryHistory(
            static function (QueryHistoryEntry $entry) use (&$recorded): void {
                $recorded[] = $entry;
            },
            static fn (string $database, int $limit): array => [],
            static function (string $database) use (&$cleared): void {
                $cleared[] = $database;
            },
        );
        $entry = $this->entry('SELECT 1');

        $history->record($entry);
        $history->clearDatabase('app');

        self::assertSame([$entry], $recorded);
        self::assertSame(['app'], $cleared);
    }

    private function entry(string $sql): QueryHistoryEntry
    {
        return new QueryHistoryEntry('app', $sql, 1.0, new \DateTimeImmutable('2026-07-21T00:00:00+00:00'), true, null);
    }
}
