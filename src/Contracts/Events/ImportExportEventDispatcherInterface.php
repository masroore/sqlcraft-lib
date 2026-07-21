<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

interface ImportExportEventDispatcherInterface
{
    public function importStarted(ConnectionInterface $connection, object $source, ?int $estimatedBytes): void;

    public function importProgress(ConnectionInterface $connection, int $bytesProcessed, int $statementsExecuted, float $elapsedMs): void;

    /** @param list<object> $errors */
    public function importFinished(ConnectionInterface $connection, int $statementsExecuted, array $errors, float $elapsedMs): void;

    public function importFailed(ConnectionInterface $connection, \Throwable $exception, ?string $lastSql, float $elapsedMs): void;

    /** @param list<string> $tables */
    public function exportStarted(ConnectionInterface $connection, object $target, string $format, array $tables): void;

    public function exportProgress(ConnectionInterface $connection, int $tablesExported, int $rowsExported, float $elapsedMs): void;

    public function exportFinished(ConnectionInterface $connection, int $tablesExported, int $rowsExported, float $elapsedMs): void;
}
