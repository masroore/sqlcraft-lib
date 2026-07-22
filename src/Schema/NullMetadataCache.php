<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use SQLCraft\Contracts\Metadata\MetadataCacheInterface;

final class NullMetadataCache implements MetadataCacheInterface
{
    /** @template T @param callable(): T $loader @return T */
    #[\Override]
    public function remember(string $key, callable $loader, int $ttl = 0): mixed
    {
        return $loader();
    }

    #[\Override]
    public function invalidateTable(string $database, string $table): void {}

    #[\Override]
    public function invalidateDatabase(string $database): void {}

    #[\Override]
    public function clear(): void {}
}
