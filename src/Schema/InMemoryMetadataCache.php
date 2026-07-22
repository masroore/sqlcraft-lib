<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use SQLCraft\Contracts\Metadata\MetadataCacheInterface;

final class InMemoryMetadataCache implements MetadataCacheInterface
{
    /** @var array<string, array{value: mixed, expires: int}> */
    private array $entries = [];

    #[\Override]
    public function remember(string $key, callable $loader, int $ttl = 0): mixed
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry !== null && ($entry['expires'] === 0 || $entry['expires'] > time())) {
            /** @psalm-suppress MixedReturnStatement */
            return $entry['value'];
        }

        $value = $loader();
        $this->entries[$key] = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0];

        /** @psalm-suppress MixedReturnStatement */
        return $value;
    }

    #[\Override]
    public function invalidateTable(string $database, string $table): void
    {
        $this->remove($database.'/table:'.$table);
    }

    #[\Override]
    public function invalidateDatabase(string $database): void
    {
        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $database.'/')) {
                unset($this->entries[$key]);
            }
        }
    }

    #[\Override]
    public function clear(): void
    {
        $this->entries = [];
    }

    private function remove(string $needle): void
    {
        foreach (array_keys($this->entries) as $key) {
            if ($key === $needle || str_contains($key, $needle)) {
                unset($this->entries[$key]);
            }
        }
    }
}
