<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;
use RuntimeException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PreparedStatementInterface;
use SQLCraft\Contracts\Import\CsvImporterInterface;
use SQLCraft\Contracts\Import\ImportSourceInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class CsvImporter implements CsvImporterInterface
{
    public function __construct(private ColumnInspectorInterface $columns)
    {
    }

    #[\Override]
    public function importCsv(
        ConnectionInterface $conn,
        QualifiedName $table,
        ImportSourceInterface $source,
        CsvImportOptions $options,
    ): ImportResult {
        $stream = $source->openStream();
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('CSV source must provide an open stream resource.');
        }

        $startedAt = hrtime(true);
        $header = fgetcsv($stream, 0, $options->separator);
        if (!is_array($header)) {
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
                $row = fgetcsv($stream, 0, $options->separator);
                if (!is_array($row)) {
                    break;
                }
                if (count($row) < count($header)) {
                    $row = [...$row, ...array_fill(0, count($header) - count($row), null)];
                }
                $batch[] = $this->mapRow($row, $known, $options->nullRepresentation);
                if (count($batch) >= $options->batchSize) {
                    $this->executeBatch($conn, $table, $known, $batch, $options);
                    $statements++;
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->executeBatch($conn, $table, $known, $batch, $options);
                $statements++;
            }
            $transaction?->commit();
        } catch (\Throwable $error) {
            if ($transaction?->isActive() === true) {
                $transaction->rollback();
            }
            throw $error;
        }

        return new ImportResult($statements, 0, [], $this->elapsedMs($startedAt));
    }

    /**
     * @param list<string> $header
     * @param iterable<ColumnMeta> $metadata
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
     * @param list<string|null> $row
     * @param list<array{0: int, 1: string, 2: bool}> $known
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
     * @param list<array{0: int, 1: string, 2: bool}> $known
     * @param list<list<string|null>> $rows
     */
    private function executeBatch(
        ConnectionInterface $conn,
        QualifiedName $table,
        array $known,
        array $rows,
        CsvImportOptions $options,
    ): PreparedStatementInterface {
        $columns = array_map(static fn (array $column): string => $column[1], $known);
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $prefix = $this->insertPrefix($conn, $options->upsertMode);
        $sql = $prefix . ' INTO ' . $this->quoteTable($conn, $table)
            . ' (' . implode(', ', array_map($conn->quoteIdentifier(...), $columns)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $placeholderRow));
        $params = array_merge(...$rows);
        $statement = $conn->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    private function insertPrefix(ConnectionInterface $conn, UpsertMode $mode): string
    {
        if ($mode === UpsertMode::Insert) {
            return 'INSERT';
        }
        if ($conn->getPlatformName() === 'sqlite') {
            return $mode === UpsertMode::InsertOrIgnore ? 'INSERT OR IGNORE' : 'INSERT OR REPLACE';
        }
        if ($conn->getPlatformName() === 'mysql' || $conn->getPlatformName() === 'mariadb') {
            return $mode === UpsertMode::InsertOrIgnore ? 'INSERT IGNORE' : 'REPLACE';
        }

        return 'INSERT';
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
