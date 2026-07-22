<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Metadata\CheckConstraintInspector;
use SQLCraft\Metadata\ColumnInspector;
use SQLCraft\Metadata\DatabaseInspector;
use SQLCraft\Metadata\ExportSource;
use SQLCraft\Metadata\ForeignKeyInspector;
use SQLCraft\Metadata\IndexInspector;
use SQLCraft\Metadata\MetadataFactoryInterface;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\Metadata\PostgreSQLMetadataFactory;
use SQLCraft\Metadata\PrivilegeInspector;
use SQLCraft\Metadata\RoutineInspector;
use SQLCraft\Metadata\SequenceInspector;
use SQLCraft\Metadata\ServerInspector;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\Metadata\SqlServerMetadataFactory;
use SQLCraft\Metadata\TableInspector;
use SQLCraft\Metadata\TriggerInspector;
use SQLCraft\Metadata\UserInspector;
use SQLCraft\Metadata\ViewInspector;

final class SchemaManagerFactory
{
    public static function forConnection(
        ConnectionInterface $connection,
        ?MetadataCacheInterface $cache = null,
        ?SchemaEventDispatcherInterface $events = null,
    ): SchemaManager {
        $factory = self::metadataFactory($connection);

        return new SchemaManager(
            serverInspector: new ServerInspector($factory),
            databaseInspector: new DatabaseInspector($factory),
            tableInspector: new TableInspector($factory),
            columnInspector: new ColumnInspector($factory),
            indexInspector: new IndexInspector($factory),
            foreignKeyInspector: new ForeignKeyInspector($factory),
            viewInspector: new ViewInspector($factory),
            routineInspector: new RoutineInspector($factory),
            triggerInspector: new TriggerInspector($factory),
            sequenceInspector: new SequenceInspector($factory),
            checkConstraintInspector: new CheckConstraintInspector($factory),
            userInspector: new UserInspector($factory),
            cache: $cache,
            events: $events,
            privilegeInspector: new PrivilegeInspector,
        );
    }

    public static function exportSourceForConnection(ConnectionInterface $connection): ExportSource
    {
        $factory = self::metadataFactory($connection);

        return new ExportSource(
            new TableInspector($factory),
            new ColumnInspector($factory),
            new TriggerInspector($factory),
            new RoutineInspector($factory),
            new ServerInspector($factory),
            new ForeignKeyInspector($factory),
        );
    }

    public static function metadataFactory(ConnectionInterface $connection): MetadataFactoryInterface
    {
        return match ($connection->getPlatformName()) {
            'mysql', 'mariadb' => new MySQLMetadataFactory,
            'pgsql' => new PostgreSQLMetadataFactory,
            'sqlite' => new SqliteMetadataFactory,
            'sqlserver' => new SqlServerMetadataFactory,
            default => throw new InvalidArgumentException(sprintf(
                'No metadata factory is registered for platform %s.',
                $connection->getPlatformName(),
            )),
        };
    }
}
