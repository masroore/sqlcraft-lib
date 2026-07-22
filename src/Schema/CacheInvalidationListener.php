<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Events\AfterDdlExecuted;
use SQLCraft\Events\SchemaChangedEvent;

/** Invalidates metadata affected by schema mutations. */
final readonly class CacheInvalidationListener
{
    public function __construct(private MetadataCacheInterface $cache) {}

    public function __invoke(object $event): void
    {
        if (! $event instanceof AfterDdlExecuted && ! $event instanceof SchemaChangedEvent) {
            return;
        }

        $database = $event->connection->getDatabaseName() ?? '';
        $objectName = $event->objectName;
        if ($objectName === '') {
            $this->cache->clear();

            return;
        }

        if ($event instanceof SchemaChangedEvent && strcasecmp($event->objectType, 'database') === 0) {
            $this->cache->invalidateDatabase($objectName);

            return;
        }

        $this->cache->invalidateTable($database, $this->unqualify($objectName));
    }

    private function unqualify(string $name): string
    {
        $parts = explode('.', $name);

        return trim(end($parts), '`[]"');
    }
}
