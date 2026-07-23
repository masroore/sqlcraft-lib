<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Metadata\ExportSource;
use SQLCraft\Metadata\MetadataInspectorSet;

final class SchemaManagerFactory
{
    public static function schemaManager(MetadataInspectorSet $inspectors, ?MetadataCacheInterface $cache = null, ?SchemaEventDispatcherInterface $events = null): SchemaManager
    {
        return new SchemaManager(
            $inspectors->server(),
            $inspectors->database(),
            $inspectors->table(),
            $inspectors->column(),
            $inspectors->index(),
            $inspectors->foreignKeys(),
            $inspectors->view(),
            $inspectors->routine(),
            $inspectors->trigger(),
            $inspectors->sequence(),
            $inspectors->checkConstraint(),
            $inspectors->user(),
            $cache,
            $events,
            $inspectors->privileges(),
        );
    }

    public static function exportSource(MetadataInspectorSet $inspectors): ExportSource
    {
        return new ExportSource($inspectors->table(), $inspectors->column(), $inspectors->trigger(), $inspectors->routine(), $inspectors->server(), $inspectors->foreignKeys());
    }
}
