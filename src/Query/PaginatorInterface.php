<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Contracts\Connection\ConnectionInterface;

interface PaginatorInterface
{
    public function paginate(ConnectionInterface $connection, SelectQuery $query, PaginationParams $params): Page;
}
