<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use DateTimeImmutable;
use InvalidArgumentException;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Export\Html\TemplateEngineFactory;
use Stringable;

/**
 * Renders a multi-table HTML document via Blade-like or Twig templates.
 *
 * Buffers all rows in memory before rendering — suitable for moderate exports,
 * not multi-GB dumps.
 */
final class HtmlFormatWriter implements FormatWriterInterface
{
    /**
     * @var list<array{name: string, columns: list<ColumnMeta>, rows: list<array<string, mixed>>}>
     */
    private array $tables = [];

    /** @var list<array<string, mixed>> */
    private array $currentRows = [];

    /** @var list<ColumnMeta> */
    private array $currentColumns = [];

    private string $currentTableName = '';

    #[\Override]
    public function getFormatName(): string
    {
        return 'html';
    }

    #[\Override]
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
        $this->tables = [];
        $this->currentRows = [];
        $this->currentColumns = [];
        $this->currentTableName = '';
    }

    #[\Override]
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
        $this->currentTableName = $table->name;
        $this->currentRows = [];
        $this->currentColumns = [];
    }

    /** @param list<string> $ddlStatements */
    #[\Override]
    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void
    {
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<ColumnMeta>  $columns
     */
    #[\Override]
    public function writeRows(
        SinkInterface $sink,
        TableStatus $table,
        array $rows,
        array $columns,
        DumpOptions $options,
    ): void {
        if ($this->currentColumns === []) {
            $this->currentColumns = $columns;
        }

        foreach ($rows as $row) {
            $prepared = [];
            foreach ($columns as $column) {
                $prepared[$column->name] = $this->prepareValue($row[$column->name] ?? null, $column);
            }
            $this->currentRows[] = $prepared;
        }
    }

    #[\Override]
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
        $this->tables[] = [
            'name' => $this->currentTableName,
            'columns' => $this->currentColumns,
            'rows' => $this->currentRows,
        ];
        $this->currentRows = [];
        $this->currentColumns = [];
        $this->currentTableName = '';
    }

    #[\Override]
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
        $htmlOptions = $options->htmlOptions ?? new HtmlExportOptions();
        $engine = TemplateEngineFactory::create($htmlOptions);
        $template = TemplateEngineFactory::resolveTemplate($htmlOptions);
        $sink->write($engine->render($template, $this->buildData($options, $htmlOptions)));
        $this->tables = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildData(DumpOptions $options, HtmlExportOptions $htmlOptions): array
    {
        return [
            'title' => $htmlOptions->title,
            'databaseName' => $options->scope->database ?? '',
            'exportedAt' => new DateTimeImmutable(),
            'tables' => $this->tables,
        ];
    }

    private function prepareValue(mixed $value, ColumnMeta $column): float|int|string|null
    {
        if ($value === null) {
            return null;
        }

        if ($this->isBinary($column)) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Binary column values must be strings.');
            }

            return base64_encode($value);
        }

        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value), is_string($value) => $value,
            $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException('HTML export values must be scalar, Stringable, or null.'),
        };
    }

    private function isBinary(ColumnMeta $column): bool
    {
        return in_array(strtolower($column->dataType->name), ['binary', 'varbinary', 'blob', 'bytea', 'raw', 'longblob'], true);
    }
}
