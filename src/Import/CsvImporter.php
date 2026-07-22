<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Events\ImportExportEventDispatcherInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Import\CsvImporterInterface;
use SQLCraft\Contracts\Import\ImportSourceInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\Exceptions\QueryTimeoutException;
use SQLCraft\Query\UpsertSqlRenderer;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class CsvImporter implements CsvImporterInterface
{
    public function __construct(
        private ColumnInspectorInterface $columns,
        private ?ImportExportEventDispatcherInterface $events = null,
        private ?QueryExecutorInterface $executor = null,
    ) {}

    #[\Override]
    public function importCsv(
        ConnectionInterface $conn,
        QualifiedName $table,
        ImportSourceInterface $source,
        CsvImportOptions $options,
    ): ImportResult {
        $stream = $source->openStream();
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('CSV source must provide an open stream resource.');
        }

        $startedAt = hrtime(true);
        $this->events?->importStarted($conn, $source, $source->getEstimatedSize(), 'csv');
        $header = fgetcsv($stream, 0, $options->separator, '"', '');
        if (! is_array($header)) {
            return new ImportResult(0, 0, [], $this->elapsedMs($startedAt));
        }

        $header = array_map(static fn (?string $value): string => $value ?? '', $header);
        $metadata = $this->columns->getColumns($conn, $table);
        /** @var list<array{0: int, 1: string, 2: bool}> $known */
        $known = $this->knownColumns($header, $metadata);
        if ($known === []) {
            throw new InvalidArgumentException('CSV header contains no known table columns.');
        }

        $transaction = $options->wrapInTransaction ? $conn->beginTransaction() : null;
        $statements = 0;
        $batch = [];
        try {
            while (true) {
                $row = fgetcsv($stream, 0, $options->separator, '"', '');
                if (! is_array($row)) {
                    break;
                }
                if (count($row) < count($header)) {
                    $row = [...$row, ...array_fill(0, count($header) - count($row), null)];
                }
                $batch[] = $this->mapRow($row, $known, $options->nullRepresentation);
                if (count($batch) >= $options->batchSize) {
                    $this->executeBatch($conn, $table, $known, $batch, $options);
                    $statements++;
                    $position = ftell($stream);
                    $this->events?->importProgress($conn, $position === false ? 0 : $position, $statements, $this->elapsedMs($startedAt));
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->executeBatch($conn, $table, $known, $batch, $options);
                $statements++;
                $position = ftell($stream);
                $this->events?->importProgress($conn, $position === false ? 0 : $position, $statements, $this->elapsedMs($startedAt));
            }
            $transaction?->commit();
        } catch (\Throwable $error) {
            if ($transaction?->isActive() === true) {
                $transaction->rollback();
            }
            $this->events?->importFailed($conn, $error, null, $this->elapsedMs($startedAt));
            throw $error;
        }

        $elapsedMs = $this->elapsedMs($startedAt);
        $this->events?->importFinished($conn, $statements, [], $elapsedMs);

        return new ImportResult($statements, 0, [], $elapsedMs);
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<ColumnMeta>  $metadata
     * @return list<array{0: int, 1: string, 2: bool}>
     */
    private function knownColumns(array $header, iterable $metadata): array
    {
        /** @var list<array{0: int, 1: string, 2: bool}> $result */
        $result = [];
        foreach ($header as $index => $name) {
            foreach ($metadata as $column) {
                if ($column->name === $name) {
                    $result[] = [$index, $name, $this->isBinary($column->dataType->name)];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param  list<string|null>  $row
     * @param  list<array{0: int, 1: string, 2: bool}>  $known
     * @return list<string|null>
     */
    private function mapRow(array $row, array $known, string $nullRepresentation): array
    {
        /** @var list<string|null> $values */
        $values = [];
        foreach ($known as [$index, $name, $binary]) {
            $value = $row[$index] ?? null;
            if ($value === $nullRepresentation || $value === null) {
                $values[] = null;

                continue;
            }
            if ($binary) {
                $decoded = base64_decode($value, true);
                if ($decoded === false) {
                    throw new InvalidArgumentException(sprintf('Invalid base64 value for column %s.', $name));
                }
                $values[] = $decoded;

                continue;
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: bool}>  $known
     * @param  list<list<string|null>>  $rows
     */
    private function executeBatch(
        ConnectionInterface $conn,
        QualifiedName $table,
        array $known,
        array $rows,
        CsvImportOptions $options,
    ): void {
        $columns = array_map(static fn (array $column): string => $column[1], $known);
        $quotedColumns = array_map($conn->quoteIdentifier(...), $columns);
        $row = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $clauses = UpsertSqlRenderer::clausesForName($conn->getPlatformName(), $options->upsertMode, $quotedColumns);
        $tableSql = $this->quoteTable($conn, $table);
        $values = [];
        foreach ($rows as $valuesRow) {
            $values = [...$values, ...$valuesRow];
        }
        $sql = $clauses['prefix'].' INTO '.$tableSql.' ('.implode(', ', $quotedColumns).') VALUES '
            .implode(', ', array_fill(0, count($rows), $row)).$clauses['suffix'];
        if ($options->statementTimeoutMs > 0) {
            if (! $this->executor instanceof QueryExecutorInterface) {
                throw new InvalidArgumentException('A QueryExecutor is required for CSV statement timeouts.');
            }
            if (! $this->executor->queryWithTimeout($conn, $sql, $values, $options->statementTimeoutMs) instanceof ResultInterface) {
                throw new QueryTimeoutException('Statement timeout is not supported by this platform.', $sql);
            }

            return;
        }
        $statement = $conn->prepare($sql);
        $statement->execute($values);
    }

    private function quoteTable(ConnectionInterface $conn, QualifiedName $table): string
    {
        $parts = [];
        if ($table->schema instanceof Identifier) {
            $parts[] = $conn->quoteIdentifier($table->schema->name);
        }
        $parts[] = $conn->quoteIdentifier($table->object->name);

        return implode('.', $parts);
    }

    private function isBinary(string $type): bool
    {
        return in_array(strtolower($type), ['binary', 'varbinary', 'blob', 'bytea', 'raw', 'longblob'], true);
    }

    private function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
