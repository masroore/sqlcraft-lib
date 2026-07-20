<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\CharsetCollection;
use SQLCraft\Collections\CollationCollection;
use SQLCraft\Collections\DatabaseCollection;
use SQLCraft\Collections\ProcessCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ServerInfo;

interface ServerInspectorInterface
{
    public function getServerInfo(ConnectionInterface $conn): ServerInfo;

    public function getDatabases(ConnectionInterface $conn): DatabaseCollection;

    /** @return array<string, string> */
    public function getVariables(ConnectionInterface $conn): array;

    /** @return array<string, string> */
    public function getStatus(ConnectionInterface $conn): array;

    public function getProcessList(ConnectionInterface $conn): ProcessCollection;

    public function getCharsets(ConnectionInterface $conn): CharsetCollection;

    public function getCollations(ConnectionInterface $conn, ?string $charset = null): CollationCollection;
}
