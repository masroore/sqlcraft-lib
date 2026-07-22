<?php

declare(strict_types=1);

namespace SQLCraft\Enums;

enum DatabaseDriver: string
{
    case MySQL = 'mysql';
    case MariaDB = 'mariadb';
    case PostgreSQL = 'pgsql';
    case SQLite = 'sqlite';
    case SqlServer = 'sqlserver';
}
