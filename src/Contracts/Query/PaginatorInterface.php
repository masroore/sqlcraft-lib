<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Query;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Query\Page;
use SQLCraft\Query\PaginationParams;
use SQLCraft\Query\SelectQuery;

interface PaginatorInterface
{
    public function paginate(ConnectionInterface $connection, SelectQuery $query, PaginationParams $params): Page;
}
