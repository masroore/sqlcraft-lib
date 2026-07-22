<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Capabilities\Capability;
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
    ) {}

    public function dump(
        ConnectionInterface $connection,
        TableStatus $table,
        FormatWriterInterface $writer,
        SinkInterface $sink,
        DumpOptions $options,
    ): int {
        $writer->writeTableHeader($sink, $table, $options);

        if ($options->tableStyle !== TableSectionStyle::None) {
            $ddl = $this->source->getTableDdl($connection, $table->name, $table->schema);
            $autoIncrement = $this->autoIncrementDdl($connection, $table, $options);
            if ($autoIncrement !== null) {
                $ddl[] = $autoIncrement;
            }
            if ($options->includeTriggers && $this->supports($connection, Capability::Trigger)) {
                $ddl = [...$ddl, ...$this->source->getTriggerDdl($connection, $table->name, $table->schema)];
            }
            if ($options->includeRoutines && $this->supports($connection, Capability::Routine)) {
                $ddl = [...$ddl, ...$this->source->getRoutineDdl($connection, $table->schema)];
            }
            /** @var list<string> $ddl */
            $writer->writeTableDdl($sink, $table, $ddl);
        }

        $rows = 0;
        if ($options->dataStyle !== DataStyle::None && ! $table->isView) {
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
            $rowCount++;
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

    private function supports(ConnectionInterface $connection, Capability $capability): bool
    {
        try {
            return $connection->getPlatform()->getCapabilitySet($connection->getServerVersion())->has($capability);
        } catch (\Throwable) {
            return false;
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

    private function autoIncrementDdl(
        ConnectionInterface $connection,
        TableStatus $table,
        DumpOptions $options,
    ): ?string {
        if (! $options->includeAutoIncrement || $table->autoIncrement === null) {
            return null;
        }

        if (! in_array($connection->getPlatformName(), ['mysql', 'maria'], true)) {
            return null;
        }

        $parts = [];
        if ($table->schema !== null) {
            $parts[] = $connection->quoteIdentifier($table->schema);
        }
        $parts[] = $connection->quoteIdentifier($table->name);

        return 'ALTER TABLE ' . implode('.', $parts) . ' AUTO_INCREMENT = ' . $table->autoIncrement;
    }
}
