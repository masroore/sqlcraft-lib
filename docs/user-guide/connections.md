# Connecting to Databases

This guide covers connection management, configuration, and best practices.

## Basic Connection

Create a session using `SQLCraftFactory`:

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

## Connection Parameters

The `ConnectionParameters` value object accepts:

| Parameter | Type | Description | Default |
|-----------|------|-------------|---------|
| `host` | `string` | Database host | `'localhost'` |
| `port` | `int` | Database port | Driver default |
| `socket` | `string` | Unix socket path | `null` |
| `database` | `string` | Database name | Required |
| `username` | `string` | Username | `null` |
| `password` | `string` | Password | `null` |
| `charset` | `string` | Character set | `'utf8mb4'` |
| `ssl` | `array` | SSL options | `null` |
| `driver` | `?DatabaseDriver` | Database engine (required by `SQLCraftFactory`) | `null` |
| `extras` | `array` | Driver-specific pass-through options | `[]` |

### Driver Selection

Pass a `DatabaseDriver` enum case as the `driver` parameter:

```php
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\ValueObjects\ConnectionParameters;

$params = new ConnectionParameters(
    database: 'mydb',
    driver: DatabaseDriver::MySQL,
);
```

Available cases and their corresponding engines:

| Enum case | Engine |
|---|---|
| `DatabaseDriver::MySQL` | MySQL |
| `DatabaseDriver::MariaDB` | MariaDB (uses MySQL driver internally) |
| `DatabaseDriver::PostgreSQL` | PostgreSQL |
| `DatabaseDriver::SQLite` | SQLite |
| `DatabaseDriver::SqlServer` | Microsoft SQL Server |

The `driver` parameter is required when calling `SQLCraftFactory::session()`.
It is optional (defaults to `null`) when constructing `ConnectionParameters` for
direct driver use, e.g. `new MySQLDriver(...)->connect($params)`.

## Database-Specific Configuration

### MySQL/MariaDB

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        port: 3306,
        database: 'myapp',
        username: 'root',
        password: 'secret',
        charset: 'utf8mb4',
        driver: DatabaseDriver::MySQL,
        extras: ['init_command' => 'SET sql_mode="STRICT_ALL_TABLES"']
    )
);
```

#### Unix Socket Connection

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        socket: '/var/run/mysqld/mysqld.sock',
        database: 'myapp',
        username: 'root',
        driver: DatabaseDriver::MySQL,
    )
);
```

#### SSL/TLS Connection

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        host: 'db.example.com',
        database: 'myapp',
        username: 'root',
        password: 'secret',
        ssl: [
            'ca' => '/path/to/ca.pem',
            'cert' => '/path/to/client-cert.pem',
            'key' => '/path/to/client-key.pem',
        ],
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
        extras: ['application_name' => 'MyApp']
    )
);
```

#### Schema Search Path

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        database: 'myapp',
        username: 'postgres',
        password: 'secret',
        driver: DatabaseDriver::PostgreSQL,
        extras: ['options' => '--search_path=myschema,public']
    )
);
```

### SQLite

```php
use SQLCraft\Enums\DatabaseDriver;

// File-based database
$db = $factory->session(
    new ConnectionParameters(
        database: '/var/data/myapp.sqlite3',
        driver: DatabaseDriver::SQLite,
    )
);

// In-memory database
$db = $factory->session(
    new ConnectionParameters(
        database: ':memory:',
        driver: DatabaseDriver::SQLite,
    )
);
```

#### SQLite Pragmas

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        database: '/var/data/myapp.sqlite3',
        driver: DatabaseDriver::SQLite,
        extras: [
            'init_command' => 'PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;'
        ]
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
        extras: [
            'encrypt' => true,
            'trust_server_certificate' => false
        ]
    )
);
```

#### Windows Authentication

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        database: 'myapp',
        driver: DatabaseDriver::SqlServer,
        extras: ['trusted_connection' => true]
    )
);
```

## Named Connections

Give connections a name for easy management:

```php
$primary = $factory->session(
    $primaryParams,
    name: 'primary'
);

$replica = $factory->session(
    $replicaParams,
    name: 'replica'
);

// Access by name
$connections = $factory->connections();
$primary = $connections->get('primary');
$replica = $connections->get('replica');
```

## Environment-Based Configuration

Use environment variables for credentials:

```php
use SQLCraft\Enums\DatabaseDriver;

$db = $factory->session(
    new ConnectionParameters(
        host: $_ENV['DB_HOST'],
        port: (int) $_ENV['DB_PORT'],
        database: $_ENV['DB_NAME'],
        username: $_ENV['DB_USER'],
        password: $_ENV['DB_PASSWORD'],
        driver: DatabaseDriver::from($_ENV['DB_DRIVER'] ?? 'mysql'),
    )
);
```

### Credential Providers

Use credential providers for centralized credential management:

```php
use SQLCraft\Connection\EnvCredentialProvider;
use SQLCraft\Enums\DatabaseDriver;

$credentialProvider = new EnvCredentialProvider();

$factory = new SQLCraftFactory(
    credentials: $credentialProvider
);

$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        database: 'myapp',
        driver: DatabaseDriver::MySQL,
    ),
    credentialKey: 'primary_db' // Looks up DB_PRIMARY_DB_USER and DB_PRIMARY_DB_PASSWORD
);
```

## Connection Pooling

SQLCraft maintains a connection manager for pooling:

```php
$factory = new SQLCraftFactory();

// First session creates connection
$db1 = $factory->session($params, name: 'primary');

// Second session reuses connection if name matches
$db2 = $factory->session($params, name: 'primary');

// They share the same underlying connection
assert($db1->connection() === $db2->connection());
```

## Testing Connections

### Ping/Health Check

```php
use SQLCraft\Exceptions\ConnectionException;

try {
    $result = $db->query('SELECT 1');
    echo "Connection is healthy\n";
} catch (ConnectionException $e) {
    echo "Connection lost: " . $e->getMessage() . "\n";
}
```

### Connection Info

```php
$platform = $db->connection()->getPlatform();

echo "Platform: " . $platform->getName() . "\n";

$serverInfo = $db->schema()->getServerInfo();
echo "Version: " . $serverInfo->version . "\n";
echo "Server: " . $serverInfo->serverSoftware . "\n";
```

## Error Handling

### Connection Failures

```php
use SQLCraft\Exceptions\{
    ConnectionFailedException,
    AuthenticationException,
    ConnectionLostException
};

try {
    $db = $factory->session($params);
} catch (AuthenticationException $e) {
    echo "Login failed: " . $e->getMessage() . "\n";
    echo "Host: " . $e->host . "\n";
} catch (ConnectionFailedException $e) {
    echo "Cannot connect: " . $e->getMessage() . "\n";
} catch (ConnectionLostException $e) {
    echo "Connection lost during operation\n";
}
```

## Best Practices

### 1. Use Connection Parameters Object

Don't pass raw arrays or strings:

```php
use SQLCraft\Enums\DatabaseDriver;

// ✅ Good - typed, validated
$params = new ConnectionParameters(
    host: 'localhost',
    database: 'myapp',
    driver: DatabaseDriver::MySQL,
);

// ❌ Bad - error-prone
$dsn = "mysql:host=localhost;dbname=myapp";
```

### 2. Never Hardcode Credentials

Always use environment variables or secure vaults:

```php
// ✅ Good - from environment
$password = $_ENV['DB_PASSWORD'];

// ❌ Bad - hardcoded
$password = 'secret123';
```

### 3. Enable SSL for Remote Connections

Always use SSL/TLS for production:

```php
use SQLCraft\Enums\DatabaseDriver;

// ✅ Good - encrypted
$params = new ConnectionParameters(
    host: 'remote.example.com',
    database: 'myapp',
    ssl: [
        'ca' => '/path/to/ca.pem',
        'verify_peer' => true,
    ],
    driver: DatabaseDriver::MySQL,
);
```

### 4. Set Appropriate Charset

Use `utf8mb4` for MySQL:

```php
use SQLCraft\Enums\DatabaseDriver;

// ✅ Good - supports full Unicode
$params = new ConnectionParameters(
    charset: 'utf8mb4',
    driver: DatabaseDriver::MySQL,
);

// ❌ Bad - limited character support
$params = new ConnectionParameters(
    charset: 'utf8', // Missing emoji, some CJK characters
    driver: DatabaseDriver::MySQL,
);
```

### 5. Use Named Connections for Multiple Databases

```php
$primary = $factory->session($primaryParams, name: 'primary');
$analytics = $factory->session($analyticsParams, name: 'analytics');
$cache = $factory->session($cacheParams, name: 'cache');
```

### 6. Test Connections Early

Fail fast if connection is unavailable:

```php
try {
    $db = $factory->session($params);
    $db->query('SELECT 1'); // Verify immediately
} catch (ConnectionException $e) {
    error_log("Database unavailable: " . $e->getMessage());
    exit(1);
}
```

## Framework Integration Examples

### Laravel

```php
// config/database.php
'sqlcraft' => [
    'host' => env('DB_HOST', 'localhost'),
    'port' => env('DB_PORT', 3306),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'driver' => env('DB_CONNECTION', 'mysql'),
],

// Service Provider
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

$this->app->singleton(DatabaseSession::class, function ($app) {
    $config = $app['config']['database.sqlcraft'];

    $factory = new SQLCraftFactory();
    return $factory->session(
        new ConnectionParameters(
            host: $config['host'],
            port: $config['port'],
            database: $config['database'],
            username: $config['username'],
            password: $config['password'],
            driver: DatabaseDriver::from($config['driver']),
        )
    );
});
```

### Symfony

```yaml
# config/packages/sqlcraft.yaml
parameters:
    env(DATABASE_URL): 'mysql://root:secret@localhost:3306/myapp'

services:
    SQLCraft\SQLCraftFactory: ~
    
    SQLCraft\DatabaseSession:
        factory: ['@SQLCraft\SQLCraftFactory', 'session']
        arguments:
            $parameters: '@sqlcraft.connection_parameters'
```

> **Symfony YAML note:** The `driver` parameter requires a `DatabaseDriver` enum
> case, which cannot be expressed directly in YAML. Resolve it in a factory
> service or compiler pass using `DatabaseDriver::from($driverString)`.

## Troubleshooting

### Common Issues

**"Could not find driver"**
- Install the appropriate PDO extension (`pdo_mysql`, `pdo_pgsql`, etc.)
- Verify with `php -m | grep pdo`

**"Access denied"**
- Check username and password
- Verify user has appropriate privileges
- Check `host` field matches allowed hosts

**"Connection timeout"**
- Verify host and port are correct
- Check firewall rules
- Test with `telnet host port`

**"Unknown database"**
- Verify database name is correct
- Ensure database exists
- Check user has access to that database

### Debug Mode

Enable query logging to debug connection issues:

```php
use Psr\Log\LoggerInterface;

$logger = // ... your PSR-3 logger

$factory = new SQLCraftFactory(
    events: $eventDispatcher,
    cache: $cache,
    logger: $logger // Will log all queries
);
```

## Next Steps

- [Schema Introspection](schema-introspection.md) - Inspect database structure
- [Query Execution](query-execution.md) - Execute queries
- [Transactions](transactions.md) - Manage transactions
- [Security](security.md) - User and privilege management
