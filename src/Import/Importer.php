<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\ImportExportEventDispatcherInterface;
use SQLCraft\Contracts\Execution\BatchExecutorInterface;
use SQLCraft\Contracts\Execution\StatementBatch;
use SQLCraft\Contracts\Execution\StatementSplitterInterface;
use SQLCraft\Contracts\Execution\StreamingStatementSplitterInterface;
use SQLCraft\Contracts\Import\ImporterInterface;
use SQLCraft\Contracts\Import\ImportSourceInterface;

final readonly class Importer implements ImporterInterface
{
    private const int MAX_BATCH_SIZE = 1000;

    public function __construct(
        private StatementSplitterInterface $splitter,
        private BatchExecutorInterface $batchExecutor,
        private ?ImportExportEventDispatcherInterface $events = null,
    ) {}

    #[\Override]
    public function import(
        ConnectionInterface $conn,
        ImportSourceInterface $source,
        ImportOptions $options,
    ): ImportResult {
        $stream = $source->openStream();
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Import source must provide an open stream resource.');
        }

        $startedAt = hrtime(true);
        $this->events?->importStarted($conn, $source, $source->getEstimatedSize(), 'sql');
        $executed = 0;
        $skipped = 0;
        $errors = [];
        $transaction = null;
        $bytesProcessed = 0;
        $lastSql = null;

        try {
            if ($options->wrapInTransaction) {
                $transaction = $conn->beginTransaction();
            }

            /** @var list<string> $batch */
            $batch = [];
            foreach ($this->statements($stream) as $statement) {
                if ($this->reachedMaximum($executed, $skipped, $options)) {
                    break;
                }

                $batch[] = $statement;
                if (count($batch) < self::MAX_BATCH_SIZE) {
                    continue;
                }

                /** @var list<string> $batchForExecution */
                $batchForExecution = $batch;
                [$executed, $skipped, $errors] = $this->executeBatch(
                    $conn,
                    $batchForExecution,
                    $options,
                    $executed,
                    $skipped,
                    $errors,
                    $startedAt,
                    $bytesProcessed,
                );
                $lastSql = $statement;
                $batch = [];
            }

            if ($batch !== [] && ! $this->reachedMaximum($executed, $skipped, $options)) {
                /** @var list<string> $batchForExecution */
                $batchForExecution = $batch;
                [$executed, $skipped, $errors] = $this->executeBatch(
                    $conn,
                    $batch,
                    $options,
                    $executed,
                    $skipped,
                    $errors,
                    $startedAt,
                    $bytesProcessed,
                );
                $lastSql = $batch[array_key_last($batch)];
            }

            $transaction?->commit();
            $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->events?->importFinished($conn, $executed, $errors, $elapsedMs);
        } catch (\Throwable $error) {
            if ($transaction?->isActive() === true) {
                $transaction->rollback();
            }
            $this->events?->importFailed($conn, $error, $lastSql, (hrtime(true) - $startedAt) / 1_000_000);
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
     * @param  list<string>  $statements
     * @param  list<ImportError>  $errors
     * @return array{0: int, 1: int, 2: list<ImportError>}
     */
    private function executeBatch(
        ConnectionInterface $connection,
        array $statements,
        ImportOptions $options,
        int $executed,
        int $skipped,
        array $errors,
        int $startedAt,
        int $bytesProcessed,
    ): array {
        $remaining = $options->maxStatements === null
            ? $statements
            : array_slice($statements, 0, max(0, $options->maxStatements - $executed - $skipped));
        $skipped += count($statements) - count($remaining);
        if ($remaining === []) {
            return [$executed, $skipped, $errors];
        }

        $results = $options->statementTimeoutMs > 0
            ? $this->batchExecutor->executeBatch($connection, new StatementBatch($remaining), $options->stopOnError, $options->statementTimeoutMs)
            : $this->batchExecutor->executeBatch($connection, new StatementBatch($remaining), $options->stopOnError);
        foreach ($results as $result) {
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
                $this->events?->importProgress($connection, $bytesProcessed, $executed, (hrtime(true) - $startedAt) / 1_000_000);
            }
        }

        return [$executed, $skipped, $errors];
    }

    /**
     * @param  resource  $stream
     * @return iterable<string>
     */
    private function statements($stream): iterable
    {
        if ($this->splitter instanceof StreamingStatementSplitterInterface) {
            $statements = $this->splitter->splitStream($stream);
            /** @var \Generator<int, string> $statements */
            yield from $statements;

            return;
        }

        $batch = $this->splitter->split((string) stream_get_contents($stream));
        /** @var list<string> $statements */
        $statements = $batch->statements;
        yield from $statements;
    }

    private function reachedMaximum(int $executed, int $skipped, ImportOptions $options): bool
    {
        return $options->maxStatements !== null && $executed + $skipped >= $options->maxStatements;
    }
}
