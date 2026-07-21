<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
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
        FormatWriterInterface ...$writers,
    ) {
        $writerMap = [];
        foreach ($writers as $writer) {
            $writerMap[$writer->getFormatName()] = $writer;
        }
        $this->writers = $writerMap;
        $this->dumper = new TableDumper($source, $executor);
    }

    private readonly TableDumper $dumper;

    #[\Override]
    public function export(ConnectionInterface $conn, SinkInterface $sink, DumpOptions $options): void
    {
        $writer = $this->writer($options->format);
        $writer->writeHeader($sink, $options);

        match ($options->scope->kind) {
            ScopeKind::FilteredResult => $this->exportFiltered($conn, $sink, $writer, $options),
            ScopeKind::Tables => $this->exportSelectedTables($conn, $sink, $writer, $options),
            ScopeKind::Database, ScopeKind::AllDatabases => $this->exportDatabase($conn, $sink, $writer, $options),
        };

        $writer->writeFooter($sink, $options);
        $sink->flush();
    }

    private function exportDatabase(
        ConnectionInterface $conn,
        SinkInterface $sink,
        FormatWriterInterface $writer,
        DumpOptions $options,
    ): void {
        foreach ($this->source->getTables($conn) as $table) {
            $this->dumper->dump($conn, $table, $writer, $sink, $options);
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
        foreach ($options->scope->tables ?? [] as $tableName) {
            $table = $this->source->getTableStatus($conn, $tableName, $schema);
            $this->dumper->dump($conn, $table, $writer, $sink, $options);
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
    }

    private function writer(string $format): FormatWriterInterface
    {
        if (!isset($this->writers[$format])) {
            throw new InvalidArgumentException(sprintf('Unsupported export format: %s', $format));
        }

        return $this->writers[$format];
    }
}
