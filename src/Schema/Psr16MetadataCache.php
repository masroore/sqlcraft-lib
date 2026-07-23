<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use Psr\SimpleCache\CacheInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;

final readonly class Psr16MetadataCache implements MetadataCacheInterface
{
    public function __construct(private CacheInterface $cache, private string $prefix = 'sqlcraft:')
    {
    }

    #[\Override]
    public function remember(string $key, callable $loader, int $ttl = 0): mixed
    {
        $key = $this->prefix . $key;
        $value = $this->cache->get($key);
        if ($value !== null) {
            /** @psalm-suppress MixedReturnStatement */
            return $value;
        } $value = $loader();
        $this->cache->set($key, $value, $ttl > 0 ? $ttl : null);

        /** @psalm-suppress MixedReturnStatement */
        return $value;
    }

    #[\Override]
    public function invalidateTable(string $database, string $table): void
    {
        $this->clear();
    }

    #[\Override]
    public function invalidateDatabase(string $database): void
    {
        $this->clear();
    }

    #[\Override]
    public function clear(): void
    {
        $this->cache->clear();
    }
}
