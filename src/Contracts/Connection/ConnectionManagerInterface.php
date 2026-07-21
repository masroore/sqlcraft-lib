<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Connection;

interface ConnectionManagerInterface
{
    public function get(string $name): ConnectionInterface;

    public function add(string $name, ConnectionInterface $connection): void;

    public function closeAll(): void;
}
