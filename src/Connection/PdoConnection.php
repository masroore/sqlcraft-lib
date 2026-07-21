<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use PDO;
use PDOException;
use PDOStatement;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultColumn;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Exceptions\ConnectionClosedException;
use SQLCraft\Exceptions\QueryException;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\ServerVersion;

/** @internal */
final class PdoConnection implements ConnectionInterface
{
    private bool $closed = false;

    private int $affectedRows = 0;

    private int $savepointSequence = 0;

    public function __construct(
        private ?PDO $pdo,
        private readonly PlatformInterface $platform,
        private readonly PdoExceptionTranslator $translator,
        private readonly ?string $name = null,
        private readonly ?string $databaseName = null,
    ) {
    }

    #[\Override]
    public function getPlatformName(): string
    {
        return $this->platform->getName();
    }

    #[\Override]
    public function getServerVersion(): ServerVersion
    {
        return $this->platform->getServerVersion($this);
    }

    #[\Override]
    public function getPlatform(): PlatformInterface
    {
        return $this->platform;
    }

    #[\Override]
    public function getName(): ?string
    {
        return $this->name;
    }

    #[\Override]
    public function getDatabaseName(): ?string
    {
        return $this->databaseName;
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function execute(string $sql, array $params = []): ExecutionResult
    {
        $statement = $this->prepareStatement($sql);
        $startedAt = hrtime(true);

        try {
            $statement->execute($params);
            $this->affectedRows = $statement->rowCount();

            $lastInsertId = false;
            if (preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $sql) === 1) {
                try {
                    $lastInsertId = $this->lastInsertId();
                } catch (QueryException) {
                    // PostgreSQL raises when an INSERT did not advance a sequence.
                }
            }

            return new ExecutionResult(
                affectedRows: $this->affectedRows,
                lastInsertId: $lastInsertId === false ? '' : $lastInsertId,
                elapsedMs: (hrtime(true) - $startedAt) / 1_000_000,
                sql: $sql,
            );
        } catch (PDOException $exception) {
            throw $this->translator->translate($exception, $sql);
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function query(string $sql, array $params = [], bool $streaming = false): ResultInterface
    {
        $statement = $this->prepareStatement($sql);

        try {
            $statement->execute($params);
            $this->affectedRows = $statement->rowCount();
        } catch (PDOException $exception) {
            throw $this->translator->translate($exception, $sql);
        }

        $columns = $this->columns($statement);

        if ($streaming) {
            return new Result\StreamingResult(
                function () use ($statement, $sql): \Iterator {
                    try {
                        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                            yield $this->normalizeRow($row);
                        }
                    } catch (PDOException $exception) {
                        throw $this->translator->translate($exception, $sql);
                    }
                },
                $columns,
            );
        }

        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw $this->translator->translate($exception, $sql);
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            $normalizedRows[] = $this->normalizeRow($row);
        }

        return new Result\BufferedResult($normalizedRows, $columns);
    }

    #[\Override]
    public function prepare(string $sql): PdoPreparedStatement
    {
        $this->assertOpen();

        return new PdoPreparedStatement($this, $sql);
    }

    #[\Override]
    public function quoteIdentifier(string $name): string
    {
        return $this->platform->quoteIdentifier(new Identifier($name));
    }

    #[\Override]
    public function quoteValue(mixed $value): string
    {
        return $this->platform->quoteValue($value);
    }

    #[\Override]
    public function lastInsertId(?string $sequenceName = null): string|false
    {
        try {
            return $this->assertOpen()->lastInsertId($sequenceName);
        } catch (PDOException $exception) {
            throw $this->translator->translate($exception);
        }
    }

    #[\Override]
    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    #[\Override]
    public function beginTransaction(string $isolationLevel = ''): Transaction
    {
        $pdo = $this->assertOpen();

        if (!$pdo->inTransaction()) {
            try {
                $pdo->beginTransaction();
            } catch (PDOException $exception) {
                throw $this->translator->translate($exception);
            }

            return new Transaction($this);
        }

        $savepoint = 'sqlcraft_sp_' . ++$this->savepointSequence;
        $this->execute('SAVEPOINT ' . $savepoint);

        return new Transaction($this, $isolationLevel, $savepoint);
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return !$this->closed && $this->pdo?->inTransaction() === true;
    }

    #[\Override]
    public function ping(): bool
    {
        try {
            $this->execute('SELECT 1');

            return true;
        } catch (\SQLCraft\Exceptions\ConnectionException) {
            return false;
        }
    }

    #[\Override]
    public function isConnected(): bool
    {
        return !$this->closed && $this->pdo instanceof PDO;
    }

    #[\Override]
    public function close(): void
    {
        $this->pdo = null;
        $this->closed = true;
    }

    /** @param array<string|int, mixed> $params */
    public function executePrepared(string $sql, array $params = []): ExecutionResult
    {
        return $this->execute($sql, $params);
    }

    /** @param array<string|int, mixed> $params */
    public function queryPrepared(string $sql, array $params = []): ResultInterface
    {
        return $this->query($sql, $params);
    }

    private function prepareStatement(string $sql): PDOStatement
    {
        try {
            return $this->assertOpen()->prepare($sql);
        } catch (PDOException $exception) {
            throw $this->translator->translate($exception, $sql);
        }
    }

    private function assertOpen(): PDO
    {
        if ($this->closed || !$this->pdo instanceof PDO) {
            throw new ConnectionClosedException('The database connection is closed.');
        }

        return $this->pdo;
    }

    /** @return list<ResultColumn> */
    private function columns(PDOStatement $statement): array
    {
        $columns = [];
        $count = $statement->columnCount();

        for ($index = 0; $index < $count; ++$index) {
            $meta = $statement->getColumnMeta($index);
            if ($meta === false) {
                $columns[] = new ResultColumn('column_' . $index, null, null, null, true);
                continue;
            }

            /** @var array{name: string, table?: string, native_type?: string, len?: int, flags?: list<string>, precision?: int, pdo_type?: int} $meta */
            $rawFlags = $meta['flags'] ?? null;
            $flags = is_array($rawFlags) ? $rawFlags : [];
            $columns[] = new ResultColumn(
                name: $meta['name'],
                nativeType: isset($meta['native_type']) ? $meta['native_type'] : null,
                table: isset($meta['table']) ? $meta['table'] : null,
                length: $meta['len'] ?? null,
                nullable: !in_array('not_null', $flags, true),
            );
        }

        return $columns;
    }

    /** @return array<string, int|float|string|bool|null> */
    private function normalizeRow(mixed $row): array
    {
        if (!is_array($row)) {
            throw new QueryException('The database returned an invalid result row.');
        }

        $normalized = [];
        foreach (array_keys($row) as $key) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $this->normalizeValue($row[$key]);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): int|float|string|bool|null
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        throw new QueryException('The database returned an unsupported scalar value.');
    }
}
