<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\ImportExportEventDispatcherInterface;
use SQLCraft\Contracts\Export\ExporterInterface;
use SQLCraft\Contracts\Export\ExportSourceInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;

final class Exporter implements ExporterInterface
{
    /** @var array<string, FormatWriterInterface> */
    private readonly array $writers;

    public function __construct(
        private readonly ExportSourceInterface $source,
        QueryExecutorInterface $executor,
        FormatWriterInterface|FormatRegistry|ImportExportEventDispatcherInterface|null $eventOrWriter = null,
        FormatWriterInterface ...$writers,
    ) {
        $events = $eventOrWriter instanceof ImportExportEventDispatcherInterface ? $eventOrWriter : null;
        if ($eventOrWriter instanceof FormatRegistry) {
            $writerMap = [];
            foreach ($eventOrWriter->getSupportedWriteFormats() as $format) {
                $writerMap[$format] = $eventOrWriter->getWriter($format);
            }
            $this->writers = $writerMap;
        } else {
            if ($eventOrWriter instanceof FormatWriterInterface) {
                $writers = [$eventOrWriter, ...$writers];
            }
            $writerMap = [];
            foreach ($writers as $writer) {
                $writerMap[$writer->getFormatName()] = $writer;
            }
            $this->writers = $writerMap;
        }
        $this->events = $events;
        $this->dumper = new TableDumper($source, $executor);
    }

    private readonly TableDumper $dumper;

    private readonly ?ImportExportEventDispatcherInterface $events;

    #[\Override]
    public function export(ConnectionInterface $conn, SinkInterface $sink, DumpOptions $options): void
    {
        $writer = $this->writer($options->format);
        $startedAt = hrtime(true);
        $this->events?->exportStarted($conn, $sink, $options->format, $options->scope->tables ?? []);
        $writer->writeHeader($sink, $options);

        [$tablesExported, $rowsExported] = match ($options->scope->kind) {
            ScopeKind::FilteredResult => $this->exportFiltered($conn, $sink, $writer, $options, $startedAt),
            ScopeKind::Tables => $this->exportSelectedTables($conn, $sink, $writer, $options, $startedAt),
            ScopeKind::Database => $this->exportDatabase($conn, $sink, $writer, $options, $startedAt),
            ScopeKind::AllDatabases => $this->exportAllDatabases($conn, $sink, $writer, $options, $startedAt),
        };

        $writer->writeFooter($sink, $options);
        $sink->flush();
        $this->events?->exportFinished($conn, $tablesExported, $rowsExported, (hrtime(true) - $startedAt) / 1_000_000);
    }

    /** @return array{0: int, 1: int} */
    private function exportDatabase(
        ConnectionInterface $conn,
        SinkInterface $sink,
        FormatWriterInterface $writer,
        DumpOptions $options,
        int $startedAt,
    ): array {
        $tablesExported = 0;
        $rowsExported = 0;
        foreach ($this->source->getTables($conn) as $table) {
            $rowsExported += $this->dumper->dump($conn, $table, $writer, $sink, $options);
            ++$tablesExported;
            $this->events?->exportProgress($conn, $tablesExported, $rowsExported, (hrtime(true) - $startedAt) / 1_000_000);
        }

        return [$tablesExported, $rowsExported];
    }

    /** @return array{0: int, 1: int} */
    private function exportAllDatabases(
        ConnectionInterface $conn,
        SinkInterface $sink,
        FormatWriterInterface $writer,
        DumpOptions $options,
        int $startedAt,
    ): array {
        $tables = 0;
        $rows = 0;
        foreach ($this->source->getDatabases($conn) as $database) {
            $scope = DumpScope::database($database->name);
            $databaseOptions = new DumpOptions(
                format: $options->format,
                scope: $scope,
                databaseStyle: $options->databaseStyle,
                tableStyle: $options->tableStyle,
                dataStyle: $options->dataStyle,
                includeAutoIncrement: $options->includeAutoIncrement,
                includeTriggers: $options->includeTriggers,
                includeRoutines: $options->includeRoutines,
                includeEvents: $options->includeEvents,
                includeUserTypes: $options->includeUserTypes,
                batchSize: $options->batchSize,
                csvSeparator: $options->csvSeparator,
                nullRepresentation: $options->nullRepresentation,
            );
            [$databaseTables, $databaseRows] = $this->exportDatabase($conn, $sink, $writer, $databaseOptions, $startedAt);
            $tables += $databaseTables;
            $rows += $databaseRows;
        }

        return [$tables, $rows];
    }

    /** @return array{0: int, 1: int} */
    private function exportSelectedTables(
        ConnectionInterface $conn,
        SinkInterface $sink,
        FormatWriterInterface $writer,
        DumpOptions $options,
        int $startedAt,
    ): array {
        $database = $options->scope->database;
        $schema = $database === $conn->getDatabaseName() ? null : $database;
        $tablesExported = 0;
        $rowsExported = 0;
        foreach ($options->scope->tables ?? [] as $tableName) {
            $table = $this->source->getTableStatus($conn, $tableName, $schema);
            $rowsExported += $this->dumper->dump($conn, $table, $writer, $sink, $options);
            ++$tablesExported;
            $this->events?->exportProgress($conn, $tablesExported, $rowsExported, (hrtime(true) - $startedAt) / 1_000_000);
        }

        return [$tablesExported, $rowsExported];
    }

    /** @return array{0: int, 1: int} */
    private function exportFiltered(
        ConnectionInterface $conn,
        SinkInterface $sink,
        FormatWriterInterface $writer,
        DumpOptions $options,
        int $startedAt,
    ): array {
        $database = $options->scope->database;
        $tableName = $options->scope->tables[0] ?? null;
        $sql = $options->scope->resultSql;
        if ($database === null || $tableName === null || $sql === null) {
            throw new InvalidArgumentException('Filtered export scope is incomplete.');
        }

        $schema = $database === $conn->getDatabaseName() ? null : $database;
        $table = $this->source->getTableStatus($conn, $tableName, $schema);
        $rowsExported = $this->dumper->dumpFiltered($conn, $table, $sql, $writer, $sink, $options);
        $this->events?->exportProgress($conn, 1, $rowsExported, (hrtime(true) - $startedAt) / 1_000_000);

        return [1, $rowsExported];
    }

    private function writer(string $format): FormatWriterInterface
    {
        if (!isset($this->writers[$format])) {
            throw new InvalidArgumentException(sprintf('Unsupported export format: %s', $format));
        }

        return $this->writers[$format];
    }
}
