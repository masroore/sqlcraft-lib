<?php
declare(strict_types=1);
namespace SQLCraft\Contracts\Connection;
use SQLCraft\ValueObjects\ConnectionParameters;
interface ConnectionInitializerInterface { public function initialize(ConnectionInterface $connection, ConnectionParameters $parameters): void; }
