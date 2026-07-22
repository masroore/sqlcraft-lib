<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use SQLCraft\Contracts\Metadata\MetadataCacheInterface;

/** PSR-6 adapter without a hard dependency on psr/cache. */
final readonly class Psr6MetadataCache implements MetadataCacheInterface
{
    public function __construct(private object $pool, private string $prefix = 'sqlcraft:')
    {
        if (! method_exists($pool, 'getItem') || ! method_exists($pool, 'save') || ! method_exists($pool, 'clear')) {
            throw new \InvalidArgumentException('Cache pool does not implement PSR-6 methods.');
        }
    }

    #[\Override]
    public function remember(string $key, callable $loader, int $ttl = 0): mixed
    {
        /** @var callable(string): object $getItem */
        $getItem = [$this->pool, 'getItem'];
        $item = $getItem($this->prefix . $key);
        if (method_exists($item, 'isHit') && $item->isHit() && method_exists($item, 'get')) {
            /** @psalm-suppress MixedReturnStatement */
            return $item->get();
        }
        $value = $loader();
        if (! method_exists($item, 'set')) {
            throw new \InvalidArgumentException('Cache item does not implement PSR-6 methods.');
        }
        $item->set($value);
        if ($ttl > 0 && method_exists($item, 'expiresAfter')) {
            $item->expiresAfter($ttl);
        }
        /** @var callable(object): bool $save */
        $save = [$this->pool, 'save'];
        $save($item);

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
        /** @var callable(): bool $clear */
        $clear = [$this->pool, 'clear'];
        $clear();
    }
}
