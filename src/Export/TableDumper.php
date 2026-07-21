<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Export\ExportSourceInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;

final readonly class TableDumper
{
    public function __construct(
        private ExportSourceInterface $source,
        private QueryExecutorInterface $executor,
    ) {
    }

    public function dump(
        ConnectionInterface $connection,
        TableStatus $table,
        FormatWriterInterface $writer,
        SinkInterface $sink,
        DumpOptions $options,
    ): void {
        $writer->writeTableHeader($sink, $table, $options);

        if ($options->dataStyle !== DataStyle::None && !$table->isView) {
            $this->dumpRows($connection, $table, $writer, $sink, $options, $this->selectAllSql($connection, $table));
        }

        $writer->writeTableFooter($sink, $table);
    }

    public function dumpFiltered(
        ConnectionInterface $connection,
        TableStatus $table,
        string $sql,
        FormatWriterInterface $writer,
        SinkInterface $sink,
        DumpOptions $options,
    ): void {
        $writer->writeTableHeader($sink, $table, $options);
        $this->dumpRows($connection, $table, $writer, $sink, $options, $sql);
        $writer->writeTableFooter($sink, $table);
    }

    private function dumpRows(
        ConnectionInterface $connection,
        TableStatus $table,
        FormatWriterInterface $writer,
        SinkInterface $sink,
        DumpOptions $options,
        string $sql,
    ): void {
        $columns = $this->source->getColumns($connection, $table->name, $table->schema);
        $result = $this->executor->query($connection, $sql);
        $batch = [];

        foreach ($result as $row) {
            $batch[] = $row;
            if (count($batch) >= $options->batchSize) {
                $writer->writeRows($sink, $table, $batch, $columns->map(static fn (ColumnMeta $column): ColumnMeta => $column), $options);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $writer->writeRows($sink, $table, $batch, $columns->map(static fn (ColumnMeta $column): ColumnMeta => $column), $options);
        }
    }

    private function selectAllSql(ConnectionInterface $connection, TableStatus $table): string
    {
        $parts = [];
        if ($table->schema !== null) {
            $parts[] = $connection->quoteIdentifier($table->schema);
        }
        $parts[] = $connection->quoteIdentifier($table->name);

        return 'SELECT * FROM ' . implode('.', $parts);
    }
}
