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
    ): int {
        $writer->writeTableHeader($sink, $table, $options);

        if ($options->tableStyle !== TableSectionStyle::None) {
            $writer->writeTableDdl($sink, $table, $this->source->getTableDdl($connection, $table->name, $table->schema));
        }

        $rows = 0;
        if ($options->dataStyle !== DataStyle::None && !$table->isView) {
            $rows = $this->dumpRows($connection, $table, $writer, $sink, $options, $this->selectAllSql($connection, $table));
        }

        $writer->writeTableFooter($sink, $table);

        return $rows;
    }

    public function dumpFiltered(
        ConnectionInterface $connection,
        TableStatus $table,
        string $sql,
        FormatWriterInterface $writer,
        SinkInterface $sink,
        DumpOptions $options,
    ): int {
        $writer->writeTableHeader($sink, $table, $options);
        $rows = $this->dumpRows($connection, $table, $writer, $sink, $options, $sql);
        $writer->writeTableFooter($sink, $table);

        return $rows;
    }

    private function dumpRows(
        ConnectionInterface $connection,
        TableStatus $table,
        FormatWriterInterface $writer,
        SinkInterface $sink,
        DumpOptions $options,
        string $sql,
    ): int {
        $columns = $this->source->getColumns($connection, $table->name, $table->schema);
        $result = $this->executor->query($connection, $sql);
        $batch = [];
        $rowCount = 0;

        foreach ($result as $row) {
            ++$rowCount;
            $batch[] = $row;
            if (count($batch) >= $options->batchSize) {
                $writer->writeRows($sink, $table, $batch, $columns->map(static fn (ColumnMeta $column): ColumnMeta => $column), $options);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $writer->writeRows($sink, $table, $batch, $columns->map(static fn (ColumnMeta $column): ColumnMeta => $column), $options);
        }

        return $rowCount;
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
