<?php

declare(strict_types=1);

// Two styles: manual begin/commit/rollback, and TransactionManager::transactional().

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Connection\TransactionManager;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INTEGER)');
$connection->execute('INSERT INTO accounts (id, balance) VALUES (1, 100)');

// Manual: you own commit/rollback.
$transaction = $connection->beginTransaction();
try {
    $connection->execute('UPDATE accounts SET balance = balance - 50 WHERE id = 1');
    $transaction->commit();
    echo "Transaction committed\n";
} catch (Throwable $e) {
    $transaction->rollback();
    echo "Transaction rolled back\n";
}

// Closure: commits on success, rolls back on exception, returns the closure value.
$balance = (new TransactionManager)->transactional($connection, function ($conn) {
    $conn->execute('UPDATE accounts SET balance = balance + 25 WHERE id = 1');

    return $conn->query('SELECT balance FROM accounts WHERE id = 1')->fetchAll()[0]['balance'];
});

printf("Final balance: %d\n", $balance);
$connection->close();
