<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Export;

use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Export\DumpOptions;

interface FormatWriterInterface
{
    public function getFormatName(): string;

    public function writeHeader(SinkInterface $sink, DumpOptions $options): void;

    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void;

    /** @param list<string> $ddlStatements */
    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<ColumnMeta>  $columns
     */
    public function writeRows(
        SinkInterface $sink,
        TableStatus $table,
        array $rows,
        array $columns,
        DumpOptions $options,
    ): void;

    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void;

    public function writeFooter(SinkInterface $sink, DumpOptions $options): void;
}
