<?php
declare(strict_types=1);
namespace SQLCraft\Metadata;
use SQLCraft\Contracts\Connection\ConnectionInterface;
final class BuiltInMetadataInspectorSetFactory
{ public function create(ConnectionInterface $c): MetadataInspectorSet { $f=match($c->getPlatformName()){'mysql','mariadb'=>new MySQLMetadataFactory,'pgsql'=>new PostgreSQLMetadataFactory,'sqlite'=>new SqliteMetadataFactory,'sqlserver'=>new SqlServerMetadataFactory,default=>throw new \InvalidArgumentException('No metadata factory for platform '.$c->getPlatformName())}; return (new DefaultMetadataInspectorSetFactory($f))->create($c); } }
