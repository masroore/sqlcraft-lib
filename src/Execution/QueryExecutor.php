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
use SQLCraft\Enums\QueryKind;
use SQLCraft\Events\AfterDdlExecuted;
use SQLCraft\Events\AfterQueryExecuted;
use SQLCraft\Events\BeforeDdlExecuted;
use SQLCraft\Events\BeforeQueryExecuted;
use SQLCraft\Events\QueryFailedEvent;
use SQLCraft\Events\SlowQueryDetectedEvent;
use SQLCraft\Exceptions\OperationCancelledException;

final readonly class QueryExecutor implements QueryExecutorInterface
{
    public function __construct(
        private ?QueryHistoryInterface $history = null,
        private ?EventDispatcherInterface $events = null,
        private int $slowQueryThresholdMs = 1000,
        private ?QueryInterceptorPipeline $pipeline = null,
    ) {
        if ($slowQueryThresholdMs < 0) {
            throw new InvalidArgumentException('Slow query threshold must be zero or greater.');
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function execute(ConnectionInterface $connection, string $sql, array $params = []): ExecutionResult
    {
        return $this->executeRequest($connection, $this->request($connection, $sql, $params, QueryKind::Dml));
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function query(ConnectionInterface $connection, string $sql, array $params = [], bool $buffered = false): ResultInterface
    {
        return $this->queryRequest($connection, $this->request($connection, $sql, $params, QueryKind::Select), $buffered);
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function executeDdl(ConnectionInterface $connection, string $sql, array $params = [], string $objectName = ''): void
    {
        $request = $this->request($connection, $sql, $params, QueryKind::Ddl);
        $before = new BeforeDdlExecuted($connection, $request->sql, $objectName);
        $this->events?->dispatch($before);
        $this->assertNotCancelled($before->isCancelled(), $before->cancelReason);
        $startedAt = hrtime(true);
        try {
            $connection->execute($request->sql, $request->params);
            $this->record($connection, $request->sql, $startedAt, true);
            $elapsedMs = $this->elapsedMs($startedAt);
            $this->events?->dispatch(new AfterDdlExecuted($connection, $request->sql, $objectName, $elapsedMs));
            $this->dispatchQuerySuccess($connection, $request->sql, $request->params, new ExecutionResult($connection->affectedRows(), (string) $connection->lastInsertId(), $elapsedMs, $request->sql), $startedAt);
        } catch (\Throwable $error) {
            $this->record($connection, $request->sql, $startedAt, false, $error->getMessage());
            $this->events?->dispatch(new QueryFailedEvent($connection, $request->sql, $request->params, $error, $this->elapsedMs($startedAt)));
            throw $error;
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function executeAdministrative(ConnectionInterface $connection, string $sql, array $params = []): ExecutionResult
    {
        return $this->executeRequest($connection, $this->request($connection, $sql, $params, QueryKind::Administrative));
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function executeWithTimeout(ConnectionInterface $connection, string $sql, array $params = [], int $timeoutMs = 0): ?ExecutionResult
    {
        $wrapped = $this->timeoutSql($connection, $sql, $timeoutMs);
        if ($wrapped === null) {
            return null;
        }

        return $this->executeRequest($connection, $this->request($connection, $wrapped, $params, QueryKind::Dml, $sql));
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function queryWithTimeout(ConnectionInterface $connection, string $sql, array $params = [], int $timeoutMs = 0): ?ResultInterface
    {
        $wrapped = $this->timeoutSql($connection, $sql, $timeoutMs);
        if ($wrapped === null) {
            return null;
        }

        return $this->queryRequest($connection, $this->request($connection, $wrapped, $params, QueryKind::Select, $sql), false);
    }

    private function executeRequest(ConnectionInterface $connection, QueryRequest $request): ExecutionResult
    {
        $before = new BeforeQueryExecuted($connection, $request->sql, $request->params, $request->kind);
        $this->events?->dispatch($before);
        $this->assertNotCancelled($before->isCancelled(), $before->cancelReason);
        $startedAt = hrtime(true);
        try {
            $result = $connection->execute($request->sql, $request->params);
            $this->record($connection, $request->sql, $startedAt, true);
            $this->dispatchQuerySuccess($connection, $request->sql, $request->params, $result, $startedAt);

            return $result;
        } catch (\Throwable $error) {
            $this->record($connection, $request->sql, $startedAt, false, $error->getMessage());
            $this->events?->dispatch(new QueryFailedEvent($connection, $request->sql, $request->params, $error, $this->elapsedMs($startedAt)));
            throw $error;
        }
    }

    private function queryRequest(ConnectionInterface $connection, QueryRequest $request, bool $buffered): ResultInterface
    {
        $before = new BeforeQueryExecuted($connection, $request->sql, $request->params, $request->kind);
        $this->events?->dispatch($before);
        $this->assertNotCancelled($before->isCancelled(), $before->cancelReason);
        $startedAt = hrtime(true);
        try {
            $result = $connection->query($request->sql, $request->params, streaming: ! $buffered);
            $this->record($connection, $request->sql, $startedAt, true);
            $execution = new ExecutionResult($connection->affectedRows(), (string) $connection->lastInsertId(), $this->elapsedMs($startedAt), $request->sql);
            $this->dispatchQuerySuccess($connection, $request->sql, $request->params, $execution, $startedAt);

            return $result;
        } catch (\Throwable $error) {
            $this->record($connection, $request->sql, $startedAt, false, $error->getMessage());
            $this->events?->dispatch(new QueryFailedEvent($connection, $request->sql, $request->params, $error, $this->elapsedMs($startedAt)));
            throw $error;
        }
    }

    private function timeoutSql(ConnectionInterface $connection, string $sql, int $timeoutMs): ?string
    {
        if ($timeoutMs < 0) {
            throw new InvalidArgumentException('Query timeout must be zero or greater.');
        }
        if ($timeoutMs === 0) {
            return $sql;
        }

        return $connection->getPlatform()->queryDialect()->wrapWithTimeout($sql, $timeoutMs);
    }

    /** @param array<string|int, mixed> $params */
    private function request(ConnectionInterface $connection, string $sql, array $params, QueryKind $kind, ?string $originalSql = null): QueryRequest
    {
        return $this->pipeline?->process($connection, $sql, $params, $kind, $originalSql) ?? new QueryRequest($connection, $originalSql ?? $sql, $sql, $params, $kind);
    }

    private function assertNotCancelled(bool $cancelled, string $reason): void
    {
        if ($cancelled) {
            throw new OperationCancelledException($reason === '' ? 'Operation was cancelled.' : $reason);
        }
    }

    /** @param array<string|int, mixed> $params */
    private function dispatchQuerySuccess(ConnectionInterface $connection, string $sql, array $params, ExecutionResult $result, int $startedAt): void
    {
        $elapsedMs = $this->elapsedMs($startedAt);
        $this->events?->dispatch(new AfterQueryExecuted($connection, $sql, $params, $result, $elapsedMs));
        if ($this->slowQueryThresholdMs > 0 && $elapsedMs >= $this->slowQueryThresholdMs) {
            $this->events?->dispatch(new SlowQueryDetectedEvent($connection, $sql, $params, $elapsedMs, $this->slowQueryThresholdMs));
        }
    }

    private function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    private function record(ConnectionInterface $connection, string $sql, int $startedAt, bool $success, ?string $errorMessage = null): void
    {
        if (! $this->history instanceof QueryHistoryInterface) {
            return;
        }
        $this->history->record(new QueryHistoryEntry($connection->getDatabaseName() ?? '', $sql, $this->elapsedMs($startedAt), new \DateTimeImmutable(), $success, $errorMessage));
    }
}
