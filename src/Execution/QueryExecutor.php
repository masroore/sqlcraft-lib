<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Events\AfterDdlExecuted;
use SQLCraft\Events\AfterQueryExecuted;
use SQLCraft\Events\BeforeDdlExecuted;
use SQLCraft\Exceptions\OperationCancelledException;
use SQLCraft\Events\BeforeQueryExecuted;
use SQLCraft\Events\QueryFailedEvent;
use SQLCraft\Events\SlowQueryDetectedEvent;

final readonly class QueryExecutor implements QueryExecutorInterface
{
    public function __construct(
        private ?QueryHistoryInterface $history = null,
        private ?EventDispatcherInterface $events = null,
        private int $slowQueryThresholdMs = 1000,
    ) {
        if ($slowQueryThresholdMs < 0) {
            throw new InvalidArgumentException('Slow query threshold must be zero or greater.');
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function execute(ConnectionInterface $connection, string $sql, array $params = []): ExecutionResult
    {
        $before = new BeforeQueryExecuted($connection, $sql, $params, 'DML');
        $this->events?->dispatch($before);
        $this->assertNotCancelled($before->isCancelled(), $before->cancelReason);
        $sql = $before->getSql();
        $params = $before->getParams();
        $startedAt = hrtime(true);

        try {
            $result = $connection->execute($sql, $params);
            $this->record($connection, $sql, $startedAt, true);
            $this->dispatchQuerySuccess($connection, $sql, $params, $result, $startedAt);

            return $result;
        } catch (\Throwable $error) {
            $this->record($connection, $sql, $startedAt, false, $error->getMessage());
            $this->events?->dispatch(new QueryFailedEvent($connection, $sql, $params, $error, $this->elapsedMs($startedAt)));
            throw $error;
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function query(ConnectionInterface $connection, string $sql, array $params = [], bool $buffered = false): ResultInterface
    {
        $before = new BeforeQueryExecuted($connection, $sql, $params, 'SELECT');
        $this->events?->dispatch($before);
        $this->assertNotCancelled($before->isCancelled(), $before->cancelReason);
        $sql = $before->getSql();
        $params = $before->getParams();
        $startedAt = hrtime(true);

        try {
            $result = $connection->query($sql, $params, streaming: !$buffered);
            $this->record($connection, $sql, $startedAt, true);
            $execution = new ExecutionResult(
                affectedRows: $connection->affectedRows(),
                lastInsertId: (string) $connection->lastInsertId(),
                elapsedMs: $this->elapsedMs($startedAt),
                sql: $sql,
            );
            $this->dispatchQuerySuccess($connection, $sql, $params, $execution, $startedAt);

            return $result;
        } catch (\Throwable $error) {
            $this->record($connection, $sql, $startedAt, false, $error->getMessage());
            $this->events?->dispatch(new QueryFailedEvent($connection, $sql, $params, $error, $this->elapsedMs($startedAt)));
            throw $error;
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function executeDdl(ConnectionInterface $connection, string $sql, array $params = [], string $objectName = ''): void
    {
        $before = new BeforeDdlExecuted($connection, $sql, $objectName);
        $this->events?->dispatch($before);
        $this->assertNotCancelled($before->isCancelled(), $before->cancelReason);
        $startedAt = hrtime(true);

        try {
            $connection->execute($sql, $params);
            $this->record($connection, $sql, $startedAt, true);
            $elapsedMs = $this->elapsedMs($startedAt);
            $this->events?->dispatch(new AfterDdlExecuted($connection, $sql, $objectName, $elapsedMs));
            $this->events?->dispatch(new AfterQueryExecuted(
                $connection,
                $sql,
                $params,
                new ExecutionResult($connection->affectedRows(), (string) $connection->lastInsertId(), $elapsedMs, $sql),
                $elapsedMs,
            ));
        } catch (\Throwable $error) {
            $this->record($connection, $sql, $startedAt, false, $error->getMessage());
            $this->events?->dispatch(new QueryFailedEvent($connection, $sql, $params, $error, $this->elapsedMs($startedAt)));
            throw $error;
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function queryWithTimeout(ConnectionInterface $connection, string $sql, array $params = [], int $timeoutMs = 0): ?ResultInterface
    {
        if ($timeoutMs < 0) {
            throw new InvalidArgumentException('Query timeout must be zero or greater.');
        }
        if ($timeoutMs === 0) {
            return $this->query($connection, $sql, $params);
        }

        $wrapped = $connection->getPlatform()->wrapWithTimeout($sql, $timeoutMs);
        if ($wrapped === null) {
            return null;
        }

        return $this->query($connection, $wrapped, $params);
    }

    /** @param array<string|int, mixed> $params */
    private function dispatchQuerySuccess(
        ConnectionInterface $connection,
        string $sql,
        array $params,
        ExecutionResult $result,
        int $startedAt,
    ): void {
        $elapsedMs = $this->elapsedMs($startedAt);
        $this->events?->dispatch(new AfterQueryExecuted($connection, $sql, $params, $result, $elapsedMs));
        if ($this->slowQueryThresholdMs > 0 && $elapsedMs >= $this->slowQueryThresholdMs) {
            $this->events?->dispatch(new SlowQueryDetectedEvent($connection, $sql, $params, $elapsedMs, $this->slowQueryThresholdMs));
        }
    }

    private function assertNotCancelled(bool $cancelled, string $reason): void
    {
        if ($cancelled) {
            throw new OperationCancelledException($reason === '' ? 'Operation was cancelled.' : $reason);
        }
    }

    private function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    private function record(ConnectionInterface $connection, string $sql, int $startedAt, bool $success, ?string $errorMessage = null): void
    {
        if (!$this->history instanceof QueryHistoryInterface) {
            return;
        }

        $this->history->record(new QueryHistoryEntry(
            database: $connection->getDatabaseName() ?? '',
            sql: $sql,
            elapsedMs: (hrtime(true) - $startedAt) / 1_000_000,
            executedAt: new \DateTimeImmutable(),
            success: $success,
            errorMessage: $errorMessage,
        ));
    }
}
