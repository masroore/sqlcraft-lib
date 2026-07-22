<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Connection\TransactionManager;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INTEGER)');
$connection->execute('INSERT INTO accounts (id, balance) VALUES (1, 100)');

// Manual transaction with commit/rollback
$transaction = $connection->beginTransaction();
try {
    $connection->execute('UPDATE accounts SET balance = balance - 50 WHERE id = 1');
    $transaction->commit();
    echo "Transaction committed\n";
} catch (Throwable $e) {
    $transaction->rollback();
    echo "Transaction rolled back\n";
}

// Automatic transaction with closure
$manager = new TransactionManager;
$balance = $manager->transactional($connection, function ($conn) {
    $conn->execute('UPDATE accounts SET balance = balance + 25 WHERE id = 1');
    $rows = $conn->query('SELECT balance FROM accounts WHERE id = 1')->fetchAll();

    return $rows[0]['balance'];
});

printf("Final balance: %d\n", $balance);
$connection->close();
