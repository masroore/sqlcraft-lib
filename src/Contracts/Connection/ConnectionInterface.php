<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Connection;

use SQLCraft\Connection\Transaction;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\ValueObjects\ServerVersion;

interface ConnectionInterface
{
    public function getPlatformName(): string;

    public function getServerVersion(): ServerVersion;

    public function getPlatform(): PlatformInterface;

    public function getName(): ?string;

    /** @param array<string|int, mixed> $params */
    public function execute(string $sql, array $params = []): ExecutionResult;

    /** @param array<string|int, mixed> $params */
    public function query(string $sql, array $params = [], bool $streaming = false): ResultInterface;

    public function prepare(string $sql): PreparedStatementInterface;

    public function quoteIdentifier(string $name): string;

    public function quoteValue(mixed $value): string;

    public function lastInsertId(?string $sequenceName = null): string|int|false;

    public function affectedRows(): int;

    public function beginTransaction(string $isolationLevel = ''): Transaction;

    public function inTransaction(): bool;

    public function ping(): bool;

    public function isConnected(): bool;

    public function close(): void;
}
