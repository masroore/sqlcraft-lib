# Security and Privileges

This guide covers user management, privilege control, and security best practices.

## Security Guard

The `SecurityGuardInterface` validates operations before execution:

```php
$guard = $db->security();

// Check if current user can perform operation
if ($guard->canDropTable('users')) {
    $db->ddl()->execute($db->ddl()->dropTable('users'));
} else {
    echo "Insufficient privileges\n";
}

// Check specific privileges
if ($guard->hasPrivilege('SELECT', 'orders')) {
    // User can SELECT from orders
}
```

## User Management

### Listing Users

```php
$users = $db->users()->list();

foreach ($users as $user) {
    printf(
        "User: %s@%s\n",
        $user->username,
        $user->host
    );
}
```

### Creating Users

```php
use SQLCraft\ValueObjects\Credential;

// Create user with password
$db->users()->create(
    new Credential(
        username: 'appuser',
        password: 'secure_password',
        host: 'localhost'
    )
);

// MySQL-style host wildcard
$db->users()->create(
    new Credential(
        username: 'apiuser',
        password: 'api_secret',
        host: '%' // Any host
    )
);
```

### Modifying Users

```php
// Change password
$db->users()->changePassword('appuser', 'new_password', host: 'localhost');

// Rename user (MySQL/MariaDB)
$db->users()->rename(
    from: 'oldname@localhost',
    to: 'newname@localhost'
);
```

### Dropping Users

```php
$db->users()->drop('appuser', host: 'localhost');
```

## Privilege Management

### Granting Privileges

```php
use SQLCraft\ValueObjects\Privilege;

// Grant SELECT on specific table
$db->privileges()->grant(
    privileges: [Privilege::Select],
    on: 'orders',
    to: 'appuser@localhost'
);

// Grant multiple privileges
$db->privileges()->grant(
    privileges: [Privilege::Select, Privilege::Insert, Privilege::Update],
    on: 'products',
    to: 'appuser@localhost'
);

// Grant all privileges on database
$db->privileges()->grant(
    privileges: [Privilege::All],
    on: 'myapp.*',
    to: 'admin@localhost'
);

// Grant with GRANT OPTION
$db->privileges()->grant(
    privileges: [Privilege::Select],
    on: 'reports',
    to: 'analyst@localhost',
    withGrantOption: true
);
```

### Available Privileges

Common privileges:
- `Privilege::Select`
- `Privilege::Insert`
- `Privilege::Update`
- `Privilege::Delete`
- `Privilege::Create`
- `Privilege::Drop`
- `Privilege::Alter`
- `Privilege::Index`
- `Privilege::CreateView`
- `Privilege::ShowView`
- `Privilege::CreateRoutine`
- `Privilege::AlterRoutine`
- `Privilege::Execute`
- `Privilege::Trigger`
- `Privilege::References`
- `Privilege::All`

Administrative privileges:
- `Privilege::CreateUser`
- `Privilege::Grant`
- `Privilege::Super`
- `Privilege::Process`
- `Privilege::Reload`
- `Privilege::Shutdown`
- `Privilege::CreateTablespace`

### Revoking Privileges

```php
// Revoke specific privilege
$db->privileges()->revoke(
    privileges: [Privilege::Delete],
    on: 'orders',
    from: 'appuser@localhost'
);

// Revoke all privileges
$db->privileges()->revoke(
    privileges: [Privilege::All],
    on: 'sensitive_data',
    from: 'tempuser@localhost'
);
```

### Listing Privileges

```php
// Get privileges for specific user
$privileges = $db->privileges()->list('appuser@localhost');

foreach ($privileges as $priv) {
    printf(
        "%s on %s: %s\n",
        $priv->privilege,
        $priv->object,
        $priv->grantable ? 'WITH GRANT OPTION' : ''
    );
}

// Get current user's privileges
$myPrivileges = $db->privileges()->list();
```

## Input Validation

SQLCraft validates all user-controlled inputs at construction time:

### Identifier Validation

```php
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\Exceptions\InvalidIdentifierException;

try {
    // Valid identifiers
    new Identifier('users');
    new Identifier('order_items');
    new Identifier('Product_2024');
    
    // Invalid - empty
    new Identifier(''); // Throws
    
    // Invalid - null byte
    new Identifier("table\0name"); // Throws
    
    // Invalid - SQL injection attempt
    new Identifier('users; DROP TABLE--'); // Validated, but won't execute as SQL
    
} catch (InvalidIdentifierException $e) {
    echo "Invalid identifier: " . $e->getMessage();
}
```

### Operator Allowlisting

Only safe operators are allowed in WHERE conditions:

```php
use SQLCraft\Query\WhereCondition;

// Valid operators
new WhereCondition('age', '=', 25);
new WhereCondition('age', '>', 18);
new WhereCondition('name', 'LIKE', 'John%');
new WhereCondition('id', 'IN', [1, 2, 3]);

// Invalid operator throws immediately
try {
    new WhereCondition('id', 'OR 1=1;--', 1); // Throws
} catch (\InvalidArgumentException $e) {
    echo "Invalid operator\n";
}
```

Valid operators:
- `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`
- `LIKE`, `NOT LIKE`
- `IN`, `NOT IN`
- `IS NULL`, `IS NOT NULL`
- `BETWEEN`, `NOT BETWEEN`

### Parameter Binding

Values are never interpolated into SQL:

```php
// ✅ Safe - uses parameters
$db->query(
    'SELECT * FROM users WHERE email = ?',
    [$userInput]
);

// SQL sent to database:
// "SELECT * FROM users WHERE email = ?"
// Parameters: ["user@example.com"]

// ❌ Never do this - SQL injection risk
$db->query("SELECT * FROM users WHERE email = '$userInput'");
```

## Credential Redaction

Passwords and sensitive data are redacted from logs and exceptions:

```php
use SQLCraft\ValueObjects\ConnectionParameters;

$params = new ConnectionParameters(
    host: 'db.example.com',
    database: 'myapp',
    username: 'root',
    password: 'secret123' // Marked with #[\SensitiveParameter]
);

// Exception messages never contain passwords
try {
    $db = $factory->session($params);
} catch (\Exception $e) {
    // Message: "Connection failed to db.example.com"
    // NOT: "Connection failed with password 'secret123'"
    echo $e->getMessage();
}
```

## Resource Limits

Protect against denial of service:

### Query Timeout

```php
use SQLCraft\Execution\QueryOptions;

// Set timeout for expensive queries
$result = $db->query(
    'SELECT * FROM huge_table WHERE complex_condition',
    [],
    options: new QueryOptions(timeoutSeconds: 30)
);
```

### Result Set Limits

```php
use SQLCraft\Query\PaginationParams;

// Limit page size
$result = $db->query('SELECT * FROM orders')
    ->paginate(
        new PaginationParams(
            page: 1,
            pageSize: 100,
            maxLimit: 1000 // Hard cap
        )
    );
```

### Statement Count Limits

```php
use SQLCraft\Import\ImportOptions;

// Limit import to 10,000 statements
$result = $db->import()
    ->fromFile('/tmp/import.sql')
    ->withOptions(
        new ImportOptions(
            maxStatements: 10000,
            stopOnError: true
        )
    )
    ->run();
```

## Platform-Specific Security

### MySQL/MariaDB

```php
// Use mysql_native_password or caching_sha2_password
$db->users()->create(
    new Credential(
        username: 'appuser',
        password: 'secure_pass',
        host: 'localhost',
        authPlugin: 'caching_sha2_password'
    )
);

// Require SSL
$db->privileges()->requireSsl('appuser@localhost');

// Limit connections
$db->users()->setResourceLimits(
    'appuser@localhost',
    maxConnections: 10,
    maxQueriesPerHour: 1000
);
```

### PostgreSQL

```php
// Create role
$db->query('CREATE ROLE readonly');
$db->privileges()->grant([Privilege::Select], on: 'ALL TABLES IN SCHEMA public', to: 'readonly');

// Grant role to user
$db->query('GRANT readonly TO appuser');

// Set connection limit
$db->query('ALTER USER appuser CONNECTION LIMIT 5');
```

### SQL Server

```php
// Create login
$db->query("CREATE LOGIN appuser WITH PASSWORD = 'SecureP@ss123'");

// Create database user
$db->query('CREATE USER appuser FOR LOGIN appuser');

// Add to role
$db->query('ALTER ROLE db_datareader ADD MEMBER appuser');
```

## Best Practices

### 1. Principle of Least Privilege

Grant only necessary permissions:

```php
// ✅ Good - minimal permissions
$db->privileges()->grant(
    [Privilege::Select, Privilege::Insert],
    on: 'orders',
    to: 'appuser@localhost'
);

// ❌ Bad - excessive permissions
$db->privileges()->grant(
    [Privilege::All],
    on: '*.*',
    to: 'appuser@localhost'
);
```

### 2. Use Application Users, Not Root

```php
// ✅ Good - dedicated application user
$appDb = $factory->session(
    new ConnectionParameters(
        username: 'myapp_rw',
        password: $_ENV['APP_DB_PASSWORD'],
        extras: ['driver' => 'mysql']
    )
);

// ❌ Bad - root/admin user
$db = $factory->session(
    new ConnectionParameters(
        username: 'root',
        password: 'root',
        extras: ['driver' => 'mysql']
    )
);
```

### 3. Separate Read and Write Connections

```php
$readOnlyDb = $factory->session($replicaParams, name: 'readonly');
$writeDb = $factory->session($primaryParams, name: 'primary');

// Use readonly for queries
$reports = $readOnlyDb->query('SELECT * FROM reports');

// Use primary for writes
$writeDb->query('INSERT INTO orders ...');
```

### 4. Enable SSL for Remote Connections

```php
// ✅ Good - encrypted connection
$params = new ConnectionParameters(
    host: 'remote.example.com',
    ssl: [
        'ca' => '/path/to/ca.pem',
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
    extras: ['driver' => 'mysql']
);
```

### 5. Audit Sensitive Operations

Use event listeners to log privilege changes:

```php
use SQLCraft\Events\AfterPrivilegeChanged;

$dispatcher->addListener(
    AfterPrivilegeChanged::class,
    function (AfterPrivilegeChanged $event) {
        error_log(sprintf(
            "Privilege %s: %s on %s to %s by %s",
            $event->action, // 'GRANT' or 'REVOKE'
            implode(', ', $event->privileges),
            $event->object,
            $event->user,
            $event->grantedBy
        ));
    }
);
```

### 6. Rotate Credentials Regularly

```php
// Monthly credential rotation
$newPassword = generateSecurePassword();
$db->users()->changePassword('appuser', $newPassword, host: 'localhost');

// Update application configuration
updateEnvFile('DB_PASSWORD', $newPassword);
```

### 7. Use Capability Checks

Don't assume privileges:

```php
if ($guard->canCreateTrigger('orders')) {
    $db->ddl()->execute(
        $db->ddl()->createTrigger(...)
    );
} else {
    // Log warning or use alternative approach
}
```

### 8. Validate Before Destructive Operations

```php
// Confirm before dropping
if ($guard->canDropDatabase('old_database')) {
    // Require explicit confirmation
    if ($confirmed) {
        $db->ddl()->execute(
            $db->ddl()->dropDatabase('old_database')
        );
    }
}
```

## Common Security Patterns

### Read-Only Application User

```php
// Create read-only user
$db->users()->create(
    new Credential(
        username: 'app_readonly',
        password: 'secure_pass',
        host: 'app-server.internal'
    )
);

// Grant SELECT only
$db->privileges()->grant(
    [Privilege::Select],
    on: 'myapp.*',
    to: 'app_readonly@app-server.internal'
);
```

### Read-Write Application User

```php
// Create read-write user
$db->users()->create(
    new Credential(
        username: 'app_rw',
        password: 'secure_pass',
        host: 'app-server.internal'
    )
);

// Grant data manipulation
$db->privileges()->grant(
    [Privilege::Select, Privilege::Insert, Privilege::Update, Privilege::Delete],
    on: 'myapp.*',
    to: 'app_rw@app-server.internal'
);
```

### Migration User

```php
// Create migration user
$db->users()->create(
    new Credential(
        username: 'app_migrate',
        password: 'secure_pass',
        host: 'deploy-server.internal'
    )
);

// Grant schema modification
$db->privileges()->grant(
    [
        Privilege::Select,
        Privilege::Insert,
        Privilege::Update,
        Privilege::Delete,
        Privilege::Create,
        Privilege::Drop,
        Privilege::Alter,
        Privilege::Index,
        Privilege::References
    ],
    on: 'myapp.*',
    to: 'app_migrate@deploy-server.internal'
);
```

## Error Handling

```php
use SQLCraft\Exceptions\InsufficientPrivilegesException;
use SQLCraft\Exceptions\UserExistsException;

try {
    $db->users()->create($credential);
} catch (UserExistsException $e) {
    echo "User already exists: " . $e->username . "\n";
} catch (InsufficientPrivilegesException $e) {
    echo "Cannot create users: " . $e->getMessage() . "\n";
}

try {
    $db->privileges()->grant($privileges, $object, $user);
} catch (InsufficientPrivilegesException $e) {
    echo "Cannot grant privileges: missing {$e->requiredPrivilege}\n";
}
```

## Next Steps

- [Transactions](transactions.md) - Manage database transactions
- [Events](events.md) - Monitor security events
- [Capabilities](../advanced/capabilities.md) - Check platform support
- [API Reference](../api/exceptions.md) - Security exceptions
