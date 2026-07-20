<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\UserCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;

interface UserInspectorInterface
{
    public function getUsers(ConnectionInterface $conn): UserCollection;
}
