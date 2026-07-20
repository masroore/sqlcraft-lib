<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

interface MetadataCacheInterface
{
    /**
     * @template T
     * @param callable(): T $loader
     * @return T
     */
    public function remember(string $key, callable $loader, int $ttl = 0): mixed;

    public function invalidateTable(string $database, string $table): void;

    public function invalidateDatabase(string $database): void;

    public function clear(): void;
}
