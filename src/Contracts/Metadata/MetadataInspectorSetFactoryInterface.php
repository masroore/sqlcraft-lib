<?php
declare(strict_types=1);
namespace SQLCraft\Contracts\Metadata;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Metadata\MetadataInspectorSet;
interface MetadataInspectorSetFactoryInterface { public function create(ConnectionInterface $connection): MetadataInspectorSet; }
