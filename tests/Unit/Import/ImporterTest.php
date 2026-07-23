<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\ImportExportEventDispatcherInterface;
use SQLCraft\Contracts\Execution\BatchExecutorInterface;
use SQLCraft\Contracts\Execution\BatchStatementResult;
use SQLCraft\Contracts\Execution\StatementBatch;
use SQLCraft\Contracts\Import\ImportSourceInterface;
use SQLCraft\Import\Importer;
use SQLCraft\Import\ImportOptions;
use SQLCraft\Query\StatementSplitter;

final class ImporterTest extends TestCase
{
    public function test_streams_source_splits_statements_and_executes_batch(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $source = self::createMock(ImportSourceInterface::class);
        $source->method('openStream')->willReturn($this->stream("CREATE TABLE orders (id INTEGER);\nINSERT INTO orders VALUES (1);\n"));
        $batchExecutor = self::createMock(BatchExecutorInterface::class);
        $batchExecutor->expects(self::once())->method('executeBatch')->with(
            $connection,
            self::callback(static fn (StatementBatch $batch): bool => $batch->statements === [
                'CREATE TABLE orders (id INTEGER)',
                'INSERT INTO orders VALUES (1)',
            ]),
            true,
        )->willReturn($this->results([
            new BatchStatementResult(0, 'CREATE TABLE orders (id INTEGER)', null, null, 0.1, null),
            new BatchStatementResult(1, 'INSERT INTO orders VALUES (1)', null, null, 0.1, null),
        ]));

        $result = (new Importer(new StatementSplitter(), $batchExecutor))->import($connection, $source, new ImportOptions());

        self::assertSame(2, $result->statementsExecuted);
        self::assertSame(0, $result->statementsSkipped);
        self::assertSame([], $result->errors);
    }

    public function test_continues_after_errors_when_stop_on_error_is_disabled(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $source = self::createMock(ImportSourceInterface::class);
        $source->method('openStream')->willReturn($this->stream('bad;good;'));
        $batchExecutor = self::createMock(BatchExecutorInterface::class);
        $batchExecutor->method('executeBatch')->willReturn($this->results([
            new BatchStatementResult(0, 'bad', null, null, 0.1, new \RuntimeException('syntax error', 1064)),
            new BatchStatementResult(1, 'good', null, null, 0.1, null),
        ]));

        $result = (new Importer(new StatementSplitter(), $batchExecutor))->import(
            $connection,
            $source,
            new ImportOptions(stopOnError: false),
        );

        self::assertSame(1, $result->statementsExecuted);
        self::assertSame(1, $result->statementsSkipped);
        self::assertSame('bad', $result->errors[0]->sql);
        self::assertSame(1064, $result->errors[0]->errorCode);
    }

    public function test_maximum_statement_limit_skips_remaining_input(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $source = self::createMock(ImportSourceInterface::class);
        $source->method('openStream')->willReturn($this->stream('one;two;three;'));
        $batchExecutor = self::createMock(BatchExecutorInterface::class);
        $batchExecutor->expects(self::once())->method('executeBatch')->with(
            $connection,
            self::callback(static fn (StatementBatch $batch): bool => $batch->statements === ['one', 'two']),
            true,
        )->willReturn($this->results([
            new BatchStatementResult(0, 'one', null, null, 0.1, null),
            new BatchStatementResult(1, 'two', null, null, 0.1, null),
        ]));

        $result = (new Importer(new StatementSplitter(), $batchExecutor))->import(
            $connection,
            $source,
            new ImportOptions(maxStatements: 2),
        );

        self::assertSame(2, $result->statementsExecuted);
        self::assertSame(1, $result->statementsSkipped);
    }

    public function test_large_source_is_processed_across_multiple_bounded_chunks(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $source = self::createMock(ImportSourceInterface::class);
        $source->method('openStream')->willReturn($this->stream(str_repeat("SELECT 1;\n", 5000)));
        $batchCalls = 0;
        $maximumBatchSize = 0;
        $batchExecutor = self::createMock(BatchExecutorInterface::class);
        $batchExecutor->method('executeBatch')->willReturnCallback(
            function (ConnectionInterface $connection, StatementBatch $batch) use (&$batchCalls, &$maximumBatchSize): \Generator {
                $batchCalls++;
                $maximumBatchSize = max($maximumBatchSize, count($batch->statements));
                foreach ($batch->statements as $index => $statement) {
                    yield new BatchStatementResult($index, $statement, null, null, 0.0, null);
                }
            },
        );

        $result = (new Importer(new StatementSplitter(), $batchExecutor))->import($connection, $source, new ImportOptions());

        self::assertSame(5000, $result->statementsExecuted);
        self::assertGreaterThan(1, $batchCalls);
        self::assertLessThanOrEqual(1000, $maximumBatchSize);
    }

    public function test_emits_progress_at_configured_statement_interval(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $source = self::createMock(ImportSourceInterface::class);
        $source->method('openStream')->willReturn($this->stream('one;two;three;four;'));
        $events = self::createMock(ImportExportEventDispatcherInterface::class);
        $events->expects(self::exactly(2))->method('importProgress')->with($connection, self::callback(static fn (int $value): bool => $value >= 0), self::callback(static fn (int|float $value): bool => $value >= 0), self::callback(static fn (float $value): bool => $value >= 0));
        $batchExecutor = self::createMock(BatchExecutorInterface::class);
        $batchExecutor->method('executeBatch')->willReturnCallback(
            function (ConnectionInterface $connection, StatementBatch $batch): \Generator {
                foreach ($batch->statements as $index => $statement) {
                    yield new BatchStatementResult($index, $statement, null, null, 0.0, null);
                }
            },
        );

        $result = (new Importer(new StatementSplitter(), $batchExecutor, $events))->import(
            $connection,
            $source,
            new ImportOptions(progressInterval: 2),
        );

        self::assertSame(4, $result->statementsExecuted);
    }

    public function test_rejects_invalid_import_options(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImportOptions(progressInterval: 0);
    }

    /** @return resource */
    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /** @param list<BatchStatementResult> $results @return \Generator<int, BatchStatementResult> */
    private function results(array $results): \Generator
    {
        yield from $results;
    }
}
