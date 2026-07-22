<?php
declare(strict_types=1);
namespace SQLCraft\Schema;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Metadata\{DefaultMetadataInspectorSetFactory,ExportSource,MetadataInspectorSet,MySQLMetadataFactory,PostgreSQLMetadataFactory,SqliteMetadataFactory,SqlServerMetadataFactory};
final class SchemaManagerFactory
{
    public static function schemaManager(MetadataInspectorSet $i, ?MetadataCacheInterface $cache=null, ?SchemaEventDispatcherInterface $events=null): SchemaManager { return new SchemaManager($i->server(),$i->database(),$i->table(),$i->column(),$i->index(),$i->foreignKeys(),$i->view(),$i->routine(),$i->trigger(),$i->sequence(),$i->checkConstraint(),$i->user(),$cache,$events,$i->privileges()); }
    public static function exportSource(MetadataInspectorSet $i): ExportSource { return new ExportSource($i->table(),$i->column(),$i->trigger(),$i->routine(),$i->server(),$i->foreignKeys()); }
    public static function forConnection(ConnectionInterface $c, ?MetadataCacheInterface $cache=null, ?SchemaEventDispatcherInterface $events=null): SchemaManager { return self::schemaManager((new \SQLCraft\Metadata\BuiltInMetadataInspectorSetFactory)->create($c),$cache,$events); }
    public static function exportSourceForConnection(ConnectionInterface $c): ExportSource { return self::exportSource((new \SQLCraft\Metadata\BuiltInMetadataInspectorSetFactory)->create($c)); }
}
