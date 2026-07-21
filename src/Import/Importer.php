<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;
use RuntimeException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\BatchExecutorInterface;
use SQLCraft\Contracts\Execution\StatementBatch;
use SQLCraft\Contracts\Execution\StatementSplitterInterface;
use SQLCraft\Contracts\Events\ImportExportEventDispatcherInterface;
use SQLCraft\Contracts\Import\ImportSourceInterface;
use SQLCraft\Contracts\Import\ImporterInterface;

final readonly class Importer implements ImporterInterface
{
    private const int CHUNK_SIZE = 8192;

    public function __construct(
        private StatementSplitterInterface $splitter,
        private BatchExecutorInterface $batchExecutor,
        private ?ImportExportEventDispatcherInterface $events = null,
    ) {
    }

    #[\Override]
    public function import(
        ConnectionInterface $conn,
        ImportSourceInterface $source,
        ImportOptions $options,
    ): ImportResult {
        $stream = $source->openStream();
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Import source must provide an open stream resource.');
        }

        $startedAt = hrtime(true);
        $this->events?->importStarted($conn, $source, $source->getEstimatedSize());
        $executed = 0;
        $skipped = 0;
        $errors = [];
        $transaction = null;

        try {
            if ($options->wrapInTransaction) {
                $transaction = $conn->beginTransaction();
            }

            $buffer = '';
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read import source stream.');
                }
                if ($chunk === '') {
                    continue;
                }

                $buffer .= $chunk;
                if ($this->endsWithStatementDelimiter($buffer)) {
                    [$executed, $skipped, $errors] = $this->executeSql(
                        $conn,
                        $buffer,
                        $options,
                        $executed,
                        $skipped,
                        $errors,
                    );
                    $buffer = '';
                    if ($this->reachedMaximum($executed, $skipped, $options)) {
                        break;
                    }
                }
            }

            if (trim($buffer) !== '' && !$this->reachedMaximum($executed, $skipped, $options)) {
                [$executed, $skipped, $errors] = $this->executeSql(
                    $conn,
                    $buffer,
                    $options,
                    $executed,
                    $skipped,
                    $errors,
                );
            }

            $transaction?->commit();
            $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->events?->importFinished($conn, $executed, $errors, $elapsedMs);
        } catch (\Throwable $error) {
            if ($transaction?->isActive() === true) {
                $transaction->rollback();
            }
            $this->events?->importFailed($conn, $error, null, (hrtime(true) - $startedAt) / 1_000_000);
            throw $error;
        }

        return new ImportResult(
            statementsExecuted: $executed,
            statementsSkipped: $skipped,
            errors: $errors,
            elapsedMs: (hrtime(true) - $startedAt) / 1_000_000,
        );
    }

    /**
     * @param list<ImportError> $errors
     * @return array{0: int, 1: int, 2: list<ImportError>}
     */
    private function executeSql(
        ConnectionInterface $connection,
        string $sql,
        ImportOptions $options,
        int $executed,
        int $skipped,
        array $errors,
    ): array {
        $batch = $this->splitter->split($sql);
        $remaining = $this->remainingStatements($batch, $executed, $options);
        $skipped += count($batch->statements) - count($remaining->statements);

        foreach ($this->batchExecutor->executeBatch($connection, $remaining, $options->stopOnError) as $result) {
            if ($result->error !== null) {
                $errors[] = new ImportError(
                    statementIndex: $executed + $result->index,
                    sql: $result->sql,
                    errorMessage: $result->error->getMessage(),
                    errorCode: (int) $result->error->getCode(),
                );
                $skipped++;
                continue;
            }

            $executed++;
            if (($executed + $skipped) % $options->progressInterval === 0) {
                $this->events?->importProgress($connection, strlen($sql), $executed, 0.0);
            }
        }

        return [$executed, $skipped, $errors];
    }

    private function remainingStatements(StatementBatch $batch, int $executed, ImportOptions $options): StatementBatch
    {
        if ($options->maxStatements === null) {
            return $batch;
        }

        $remaining = max(0, $options->maxStatements - $executed);

        return new StatementBatch(array_slice($batch->statements, 0, $remaining));
    }

    private function reachedMaximum(int $executed, int $skipped, ImportOptions $options): bool
    {
        return $options->maxStatements !== null && $executed + $skipped >= $options->maxStatements;
    }

    private function endsWithStatementDelimiter(string $sql): bool
    {
        return str_ends_with(rtrim($sql), ';');
    }
}
