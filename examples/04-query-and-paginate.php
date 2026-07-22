<?php

declare(strict_types=1);

// Typed SelectQuery + allowlisted WHERE/ORDER BY, then paginate.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Query\OrderByClause;
use SQLCraft\Query\PaginationParams;
use SQLCraft\Query\Paginator;
use SQLCraft\Query\SelectQuery;
use SQLCraft\Query\SelectQueryRenderer;
use SQLCraft\Query\WhereCondition;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

$platform = new SqlitePlatform;
$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    $platform,
))->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
foreach (['Ada', 'Grace', 'Linus'] as $name) {
    $connection->execute('INSERT INTO users (name) VALUES (?)', [$name]);
}

// SelectQuery is a structure; the renderer turns it into engine SQL.
$query = (new SelectQuery(new QualifiedName(new Identifier('users'))))
    ->withWhere(new WhereCondition(new Identifier('name'), 'LIKE', 'G%'))
    ->withOrderBy(new OrderByClause(new Identifier('name')));

// Paginator runs the count + page queries and returns a Page DTO.
$page = (new Paginator(new QueryExecutor, new SelectQueryRenderer($platform)))
    ->paginate($connection, $query, new PaginationParams(page: 1, limit: 10));

foreach ($page->rows as $row) {
    echo $row['name'], PHP_EOL;
}
