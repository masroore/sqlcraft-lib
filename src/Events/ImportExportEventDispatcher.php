<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\ImportExportEventDispatcherInterface;

final readonly class ImportExportEventDispatcher implements ImportExportEventDispatcherInterface
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}

    #[\Override]
    public function importStarted(ConnectionInterface $connection, object $source, ?int $estimatedBytes, string $format = 'sql'): void
    {
        $this->dispatcher->dispatch(new ImportStartedEvent($connection, $source, $estimatedBytes, $format));
    }

    #[\Override]
    public function importProgress(ConnectionInterface $connection, int $bytesProcessed, int $statementsExecuted, float $elapsedMs): void
    {
        $this->dispatcher->dispatch(new ImportProgressEvent($connection, $bytesProcessed, $statementsExecuted, $elapsedMs));
    }

    /** @param list<object> $errors */
    #[\Override]
    public function importFinished(ConnectionInterface $connection, int $statementsExecuted, array $errors, float $elapsedMs): void
    {
        $this->dispatcher->dispatch(new ImportFinishedEvent($connection, $statementsExecuted, $errors, $elapsedMs));
    }

    #[\Override]
    public function importFailed(ConnectionInterface $connection, \Throwable $exception, ?string $lastSql, float $elapsedMs): void
    {
        $this->dispatcher->dispatch(new ImportFailedEvent($connection, $exception, $lastSql, $elapsedMs));
    }

    /** @param list<string> $tables */
    #[\Override]
    public function exportStarted(ConnectionInterface $connection, object $target, string $format, array $tables): void
    {
        $this->dispatcher->dispatch(new ExportStartedEvent($connection, $target, $format, $tables));
    }

    /** @param list<string> $tables */
    #[\Override]
    public function exportWarning(ConnectionInterface $connection, string $message, array $tables): void
    {
        $this->dispatcher->dispatch(new ExportWarningEvent($connection, $message, $tables));
    }

    #[\Override]
    public function exportProgress(ConnectionInterface $connection, int $tablesExported, int $rowsExported, float $elapsedMs): void
    {
        $this->dispatcher->dispatch(new ExportProgressEvent($connection, $tablesExported, $rowsExported, $elapsedMs));
    }

    #[\Override]
    public function exportFinished(ConnectionInterface $connection, int $tablesExported, int $rowsExported, float $elapsedMs): void
    {
        $this->dispatcher->dispatch(new ExportFinishedEvent($connection, $tablesExported, $rowsExported, $elapsedMs));
    }
}
