<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Query\OrderByClause;
use SQLCraft\Query\Page;
use SQLCraft\Query\PaginationParams;
use SQLCraft\Query\Paginator;
use SQLCraft\Query\SelectQuery;
use SQLCraft\Query\SelectQueryRenderer;
use SQLCraft\Query\WhereCondition;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
foreach (['Ada', 'Grace', 'Linus'] as $name) {
    $connection->execute('INSERT INTO users (name) VALUES (?)', [$name]);
}

$executor = new QueryExecutor;
$renderer = new SelectQueryRenderer($platform);
$paginator = new Paginator($executor, $renderer);
$query = (new SelectQuery(new QualifiedName(new Identifier('users'))))
    ->withWhere(new WhereCondition(new Identifier('name'), 'LIKE', 'G%'))
    ->withOrderBy(new OrderByClause(new Identifier('name')));
$page = $paginator->paginate($connection, $query, new PaginationParams(page: 1, limit: 10));

/** @var Page $page */
foreach ($page->rows as $row) {
    echo $row['name'], PHP_EOL;
}
