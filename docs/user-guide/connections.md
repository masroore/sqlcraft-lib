# Connecting to Databases

This guide covers connection management, configuration, and best practices.

## Basic Connection

Create a session using `SQLCraftFactory`:

```php
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
        extras: ['driver' => 'mysql']
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
| `extras` | `array` | Driver-specific options | `[]` |

### Driver Selection

Specify the driver in the `extras` array:

```php
$params = new ConnectionParameters(
    database: 'mydb',
    extras: ['driver' => 'mysql'] // or 'pgsql', 'sqlite', 'sqlserver'
);
```

Available drivers:
- `mysql` - MySQL and MariaDB
- `pgsql` - PostgreSQL
- `sqlite` - SQLite
- `sqlserver` - Microsoft SQL Server

## Database-Specific Configuration

### MySQL/MariaDB

```php
$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        port: 3306,
        database: 'myapp',
        username: 'root',
        password: 'secret',
        charset: 'utf8mb4',
        extras: [
            'driver' => 'mysql',
            'init_command' => 'SET sql_mode="STRICT_ALL_TABLES"'
        ]
    )
);
```

#### Unix Socket Connection

```php
$db = $factory->session(
    new ConnectionParameters(
        socket: '/var/run/mysqld/mysqld.sock',
        database: 'myapp',
        username: 'root',
        extras: ['driver' => 'mysql']
    )
);
```

#### SSL/TLS Connection

```php
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
        extras: ['driver' => 'mysql']
    )
);
```

### PostgreSQL

```php
$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        port: 5432,
        database: 'myapp',
        username: 'postgres',
        password: 'secret',
        extras: [
            'driver' => 'pgsql',
            'application_name' => 'MyApp'
        ]
    )
);
```

#### Schema Search Path

```php
$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        database: 'myapp',
        username: 'postgres',
        password: 'secret',
        extras: [
            'driver' => 'pgsql',
            'options' => '--search_path=myschema,public'
        ]
    )
);
```

### SQLite

```php
// File-based database
$db = $factory->session(
    new ConnectionParameters(
        database: '/var/data/myapp.sqlite3'
    )
);

// In-memory database
$db = $factory->session(
    new ConnectionParameters(
        database: ':memory:'
    )
);
```

#### SQLite Pragmas

```php
$db = $factory->session(
    new ConnectionParameters(
        database: '/var/data/myapp.sqlite3',
        extras: [
            'init_command' => 'PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;'
        ]
    )
);
```

### SQL Server

```php
$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        port: 1433,
        database: 'myapp',
        username: 'sa',
        password: 'YourStrong@Password',
        extras: [
            'driver' => 'sqlserver',
            'encrypt' => true,
            'trust_server_certificate' => false
        ]
    )
);
```

#### Windows Authentication

```php
$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        database: 'myapp',
        extras: [
            'driver' => 'sqlserver',
            'trusted_connection' => true
        ]
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
$db = $factory->session(
    new ConnectionParameters(
        host: $_ENV['DB_HOST'],
        port: (int) $_ENV['DB_PORT'],
        database: $_ENV['DB_NAME'],
        username: $_ENV['DB_USER'],
        password: $_ENV['DB_PASSWORD'],
        extras: ['driver' => $_ENV['DB_DRIVER'] ?? 'mysql']
    )
);
```

### Credential Providers

Use credential providers for centralized credential management:

```php
use SQLCraft\Connection\EnvCredentialProvider;

$credentialProvider = new EnvCredentialProvider();

$factory = new SQLCraftFactory(
    credentials: $credentialProvider
);

$db = $factory->session(
    new ConnectionParameters(
        host: 'localhost',
        database: 'myapp',
        extras: ['driver' => 'mysql']
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
// ✅ Good - typed, validated
$params = new ConnectionParameters(
    host: 'localhost',
    database: 'myapp',
    extras: ['driver' => 'mysql']
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
// ✅ Good - encrypted
$params = new ConnectionParameters(
    host: 'remote.example.com',
    database: 'myapp',
    ssl: [
        'ca' => '/path/to/ca.pem',
        'verify_peer' => true,
    ],
    extras: ['driver' => 'mysql']
);
```

### 4. Set Appropriate Charset

Use `utf8mb4` for MySQL:

```php
// ✅ Good - supports full Unicode
$params = new ConnectionParameters(
    charset: 'utf8mb4',
    extras: ['driver' => 'mysql']
);

// ❌ Bad - limited character support
$params = new ConnectionParameters(
    charset: 'utf8', // Missing emoji, some CJK characters
    extras: ['driver' => 'mysql']
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
            extras: ['driver' => $config['driver']]
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
            $parameters: !service
                class: SQLCraft\ValueObjects\ConnectionParameters
                arguments:
                    $host: '%env(DB_HOST)%'
                    $port: '%env(int:DB_PORT)%'
                    $database: '%env(DB_NAME)%'
                    $username: '%env(DB_USER)%'
                    $password: '%env(DB_PASSWORD)%'
                    $extras: 
                        driver: '%env(DB_DRIVER)%'
```

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
