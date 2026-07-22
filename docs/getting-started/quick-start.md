# Quick Start Guide

This guide will walk you through creating your first SQLCraft application in under 10 minutes.

## Hello World: SQLite Connection

Let's start with the simplest possible example using SQLite (requires no external database):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

// Create factory
$factory = new SQLCraftFactory();

// Connect to an in-memory SQLite database
$db = $factory->session(
    new ConnectionParameters(
        database: ':memory:',
        driver: DatabaseDriver::SQLite,
    )
);

// Create a table
$db->query('CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL
)');

// Insert data
$db->query(
    'INSERT INTO users (name, email) VALUES (?, ?)',
    ['Alice', 'alice@example.com']
);

// Query data
$result = $db->query('SELECT * FROM users');
foreach ($result as $row) {
    printf("User: %s <%s>\n", $row['name'], $row['email']);
}
```

Run this and you'll see:
```
User: Alice <alice@example.com>
```

## Connecting to Real Databases

### MySQL/MariaDB

```php
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

$factory = new SQLCraftFactory();

$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        port: 3306,
        database: 'myapp',
        username: 'root',
        password: 'secret',
        driver: DatabaseDriver::MySQL,
    )
);
```

### PostgreSQL

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        port: 5432,
        database: 'myapp',
        username: 'postgres',
        password: 'secret',
        driver: DatabaseDriver::PostgreSQL,
    )
);
```

### SQL Server

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        port: 1433,
        database: 'myapp',
        username: 'sa',
        password: 'YourStrong@Password',
        driver: DatabaseDriver::SqlServer,
    )
);
```

## Schema Introspection

List all tables in your database:

```php
$tables = $db->schema()->listTables();

foreach ($tables as $table) {
    printf(
        "Table: %s (Engine: %s, Rows: ~%d)\n",
        $table->name,
        $table->engine ?? 'N/A',
        $table->rows ?? 0
    );
}
```

Get detailed information about a table:

```php
$structure = $db->schema()->describeTable('users');

echo "Columns:\n";
foreach ($structure->columns as $column) {
    printf(
        "  - %s: %s%s\n",
        $column->name,
        $column->dataType->name,
        $column->nullable ? ' NULL' : ' NOT NULL'
    );
}

echo "\nIndexes:\n";
foreach ($structure->indexes as $index) {
    printf(
        "  - %s (%s)\n",
        $index->name,
        $index->unique ? 'UNIQUE' : 'INDEX'
    );
}

echo "\nForeign Keys:\n";
foreach ($structure->foreignKeys as $fk) {
    printf(
        "  - %s → %s.%s\n",
        implode(', ', $fk->columns),
        $fk->referencedTable,
        implode(', ', $fk->referencedColumns)
    );
}
```

## Creating Tables with DDL Builder

Use the fluent DDL builder instead of writing SQL:

```php
use SQLCraft\ValueObjects\{DataType, Identifier, QualifiedName};

$ddl = $db->ddl()->createTable(
    new QualifiedName(new Identifier('orders'))
)
    ->column('id', DataType::int(), autoIncrement: true, primary: true)
    ->column('customer_id', DataType::int(), nullable: false)
    ->column('total', DataType::decimal(10, 2), nullable: false)
    ->column('status', DataType::varchar(20), nullable: false, default: 'pending')
    ->column('created_at', DataType::timestamp(), nullable: false)
    ->foreignKey(
        ['customer_id'],
        references: 'customers',
        columns: ['id'],
        onDelete: 'CASCADE'
    )
    ->index(['status', 'created_at'])
    ->engine('InnoDB');

// Preview the SQL
echo $ddl->toSql() . "\n";

// Execute it
$db->ddl()->execute($ddl);
```

## Querying Data

### Simple Queries

```php
// Direct SQL with parameters
$result = $db->query(
    'SELECT * FROM orders WHERE status = ? AND total > ?',
    ['pending', 100.00]
);

foreach ($result as $row) {
    echo "Order #{$row['id']}: \${$row['total']}\n";
}
```

### Counting Rows

```php
$result = $db->query('SELECT COUNT(*) as count FROM orders');
$count = $result->fetchColumn('count');
echo "Total orders: $count\n";
```

## Transactions

```php
try {
    $db->connection()->beginTransaction();
    
    $db->query(
        'INSERT INTO orders (customer_id, total, status) VALUES (?, ?, ?)',
        [1, 250.00, 'pending']
    );
    
    $db->query(
        'UPDATE customers SET total_orders = total_orders + 1 WHERE id = ?',
        [1]
    );
    
    $db->connection()->commit();
    echo "Order created successfully!\n";
    
} catch (\Exception $e) {
    $db->connection()->rollback();
    echo "Error: " . $e->getMessage() . "\n";
}
```

## Import and Export

### Export to SQL

```php
// Export table structure and data
$db->export()->table('users')->toFile('/tmp/users.sql', format: 'sql');

echo "Exported users table to /tmp/users.sql\n";
```

### Export to CSV

```php
$db->export()->table('users')->toFile('/tmp/users.csv', format: 'csv');

echo "Exported users table to /tmp/users.csv\n";
```

### Import from SQL

```php
$result = $db->import()->fromFile('/tmp/users.sql')->run();

printf(
    "Imported %d statements, affected %d rows\n",
    $result->statementsExecuted,
    $result->rowsAffected
);
```

## Checking Database Capabilities

Different databases support different features. Check before using:

```php
use SQLCraft\Capabilities\Capability;

$capabilities = $db->connection()->getPlatform()->getCapabilities();

if ($capabilities->has(Capability::Trigger)) {
    echo "This database supports triggers\n";
} else {
    echo "Triggers are not supported\n";
}

if ($capabilities->has(Capability::CheckConstraints)) {
    echo "This database supports CHECK constraints\n";
}

if ($capabilities->has(Capability::Sequences)) {
    echo "This database supports sequences\n";
}
```

## Error Handling

SQLCraft provides typed exceptions:

```php
use SQLCraft\Exceptions\{
    ConnectionException,
    ConstraintViolationException,
    UniqueConstraintException,
    QueryException
};

try {
    $db->query(
        'INSERT INTO users (email) VALUES (?)',
        ['duplicate@example.com']
    );
} catch (UniqueConstraintException $e) {
    echo "Email already exists: " . $e->constraintName . "\n";
} catch (ConstraintViolationException $e) {
    echo "Constraint violation: " . $e->getMessage() . "\n";
} catch (QueryException $e) {
    echo "Query error: " . $e->getMessage() . "\n";
} catch (ConnectionException $e) {
    echo "Connection lost: " . $e->getMessage() . "\n";
}
```

## Complete Example

Here's a complete working example that demonstrates most features:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\{ConnectionParameters, DataType, Identifier, QualifiedName};
use SQLCraft\Exceptions\UniqueConstraintException;

// Connect
$factory = new SQLCraftFactory();
$db = $factory->session(new ConnectionParameters(
    database: ':memory:',
    driver: DatabaseDriver::SQLite,
));

// Create schema
$db->ddl()->execute(
    $db->ddl()->createTable(new QualifiedName(new Identifier('products')))
        ->column('id', DataType::int(), autoIncrement: true, primary: true)
        ->column('name', DataType::varchar(255), nullable: false)
        ->column('price', DataType::decimal(10, 2), nullable: false)
        ->column('stock', DataType::int(), nullable: false, default: 0)
        ->index(['name'], unique: true)
);

// Insert products
$products = [
    ['Laptop', 999.99, 10],
    ['Mouse', 29.99, 50],
    ['Keyboard', 79.99, 30],
];

foreach ($products as [$name, $price, $stock]) {
    try {
        $db->query(
            'INSERT INTO products (name, price, stock) VALUES (?, ?, ?)',
            [$name, $price, $stock]
        );
        echo "Added: $name\n";
    } catch (UniqueConstraintException $e) {
        echo "Skipped duplicate: $name\n";
    }
}

// Query and display
echo "\nProduct Catalog:\n";
echo str_repeat('-', 50) . "\n";

$result = $db->query('SELECT * FROM products ORDER BY price DESC');
foreach ($result as $row) {
    printf(
        "%-20s $%8.2f (%d in stock)\n",
        $row['name'],
        $row['price'],
        $row['stock']
    );
}

// Schema info
echo "\nTable Structure:\n";
$structure = $db->schema()->describeTable('products');
foreach ($structure->columns as $column) {
    printf(
        "  %s: %s%s\n",
        $column->name,
        $column->dataType->name,
        $column->nullable ? ' NULL' : ' NOT NULL'
    );
}
```

## Next Steps

Now that you've seen the basics:

- Learn about [connection management](../user-guide/connections.md)
- Explore [schema introspection](../user-guide/schema-introspection.md) in depth
- Master [DDL operations](../user-guide/ddl-operations.md)
- Understand [the capability system](../advanced/capabilities.md)
- Check out [framework integration](../advanced/framework-integration.md) examples
