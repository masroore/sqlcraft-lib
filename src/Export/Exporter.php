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
        FormatWriterInterface|ImportExportEventDispatcherInterface|null $eventOrWriter = null,
        FormatWriterInterface ...$writers,
    ) {
        $events = $eventOrWriter instanceof ImportExportEventDispatcherInterface ? $eventOrWriter : null;
        if ($eventOrWriter instanceof FormatWriterInterface) {
            $writers = [$eventOrWriter, ...$writers];
        }
        $writerMap = [];
        foreach ($writers as $writer) {
            $writerMap[$writer->getFormatName()] = $writer;
        }
        $this->writers = $writerMap;
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

        match ($options->scope->kind) {
            ScopeKind::FilteredResult => $this->exportFiltered($conn, $sink, $writer, $options),
            ScopeKind::Tables => $this->exportSelectedTables($conn, $sink, $writer, $options),
            ScopeKind::Database, ScopeKind::AllDatabases => $this->exportDatabase($conn, $sink, $writer, $options),
        };

        $writer->writeFooter($sink, $options);
        $sink->flush();
        $this->events?->exportFinished($conn, count($options->scope->tables ?? []), 0, (hrtime(true) - $startedAt) / 1_000_000);
    }

    private function exportDatabase(
        ConnectionInterface $conn,
        SinkInterface $sink,
        FormatWriterInterface $writer,
        DumpOptions $options,
    ): void {
        $tablesExported = 0;
        foreach ($this->source->getTables($conn) as $table) {
            $this->dumper->dump($conn, $table, $writer, $sink, $options);
            $tablesExported++;
            $this->events?->exportProgress($conn, $tablesExported, 0, 0.0);
        }
    }

    private function exportSelectedTables(
        ConnectionInterface $conn,
        SinkInterface $sink,
        FormatWriterInterface $writer,
        DumpOptions $options,
    ): void {
        $database = $options->scope->database;
        $schema = $database === $conn->getDatabaseName() ? null : $database;
        $tablesExported = 0;
        foreach ($options->scope->tables ?? [] as $tableName) {
            $table = $this->source->getTableStatus($conn, $tableName, $schema);
            $this->dumper->dump($conn, $table, $writer, $sink, $options);
            $tablesExported++;
            $this->events?->exportProgress($conn, $tablesExported, 0, 0.0);
        }
    }

    private function exportFiltered(
        ConnectionInterface $conn,
        SinkInterface $sink,
        FormatWriterInterface $writer,
        DumpOptions $options,
    ): void {
        $database = $options->scope->database;
        $tableName = $options->scope->tables[0] ?? null;
        $sql = $options->scope->resultSql;
        if ($database === null || $tableName === null || $sql === null) {
            throw new InvalidArgumentException('Filtered export scope is incomplete.');
        }

        $schema = $database === $conn->getDatabaseName() ? null : $database;
        $table = $this->source->getTableStatus($conn, $tableName, $schema);
        $this->dumper->dumpFiltered($conn, $table, $sql, $writer, $sink, $options);
        $this->events?->exportProgress($conn, 1, 0, 0.0);
    }

    private function writer(string $format): FormatWriterInterface
    {
        if (!isset($this->writers[$format])) {
            throw new InvalidArgumentException(sprintf('Unsupported export format: %s', $format));
        }

        return $this->writers[$format];
    }
}
