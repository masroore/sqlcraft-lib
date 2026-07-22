# Framework Integration

SQLCraft is a framework-independent library. Its only hard runtime dependency is PHP 8.4+
and `ext-pdo`. Everything else — event dispatching, caching, logging — is expressed as
optional PSR interfaces. This means integration is always the same pattern: wire up
`SQLCraftFactory` in your container and inject it where needed.

## Design Philosophy

SQLCraft is a thin, typed layer over PDO. It is **not** a wrapper around your framework's
ORM or query builder. The intended use is:

- Use `SQLCraft` for schema introspection, DDL, bulk import/export, and privilege management.
- Keep using `Eloquent`, `Doctrine`, or your framework's query layer for day-to-day queries.
- They operate on the same database without interfering.

## Plain PHP / CLI (Zero Dependencies)

No container is required. Construct `SQLCraftFactory` directly and call `session()`.

```php
<?php
require 'vendor/autoload.php';

use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

$factory = new SQLCraftFactory();

$session = $factory->session(new ConnectionParameters(
    host: '127.0.0.1',
    port: 5432,
    database: 'mydb',
    username: 'app',
    password: 'secret',
    extras: ['driver' => 'pgsql'],
));

$tables = $session->schema()->listTables();
foreach ($tables as $table) {
    echo $table->name . "\n";
}
```

Environment variables work out of the box via `EnvCredentialProvider`:

```bash
export SQLCRAFT_HOST=127.0.0.1
export SQLCRAFT_PORT=5432
export SQLCRAFT_DATABASE=mydb
export SQLCRAFT_USERNAME=app
export SQLCRAFT_PASSWORD=secret
export SQLCRAFT_DRIVER=pgsql
```

```php
$factory = new SQLCraftFactory();         // picks up env vars automatically
$session = $factory->session(
    ConnectionParameters::fromEnv(),      // reads the env vars above
);
```

## PSR-11 Container Registration (Framework-Neutral)

Any PSR-11 container can be used. Bind the three main types:

```php
// config/container.php  (works with PHP-DI, League Container, etc.)

use Psr\Container\ContainerInterface;
use SQLCraft\Driver\DriverRegistry;
use SQLCraft\SQLCraftFactory;

return [
    SQLCraftFactory::class => static function (ContainerInterface $c): SQLCraftFactory {
        return new SQLCraftFactory(
            events: $c->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
            cache:  $c->get(\SQLCraft\Contracts\Metadata\MetadataCacheInterface::class),
        );
    },
];
```

If you use a PSR-16 simple cache (e.g. Symfony Cache, Laravel Cache):

```php
use SQLCraft\Schema\Psr16MetadataCache;

$cache = new Psr16MetadataCache($psr16Cache, ttl: 300);
$factory = new SQLCraftFactory(cache: $cache);
```

For PSR-6 pools (e.g. Symfony Cache's CacheItemPoolInterface):

```php
use SQLCraft\Schema\Psr6MetadataCache;

$cache = new Psr6MetadataCache($pool, ttl: 300);
```

## Laravel Service Provider

Add a provider in `config/app.php` or use `app/Providers/SQLCraftServiceProvider.php`.
SQLCraft operates **alongside** Eloquent; it does not replace Laravel's database layer.

```php
<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use SQLCraft\Driver\DriverRegistry;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Driver\PostgreSQLDriver;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Driver\SqlServerDriver;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\Schema\Psr16MetadataCache;
use SQLCraft\SQLCraftFactory;
use SQLCraft\DatabaseSession;
use SQLCraft\ValueObjects\ConnectionParameters;

class SQLCraftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SQLCraftFactory::class, function ($app): SQLCraftFactory {
            $pdo = new PdoConnectionFactory(new PdoExceptionTranslator());
            $drivers = new DriverRegistry([
                new MySQLDriver($pdo, new MySQLPlatform()),
                new PostgreSQLDriver($pdo, new PostgreSQLPlatform()),
                new SqliteDriver($pdo, new SqlitePlatform()),
                new SqlServerDriver($pdo, new SqlServerPlatform()),
            ]);
            $drivers->registerAlias('mariadb', $drivers->get('mysql'));

            $cache = new Psr16MetadataCache($app->make('cache.store'));

            return new SQLCraftFactory(
                drivers:     $drivers,
                events:      $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
                cache:       $cache,
            );
        });

        // Bind a default DatabaseSession for the default Laravel connection
        $this->app->singleton(DatabaseSession::class, function ($app): DatabaseSession {
            $config = $app['config']['database.connections'][$app['config']['database.default']];
            return $app->make(SQLCraftFactory::class)->session(
                new ConnectionParameters(
                    host:     $config['host'] ?? '127.0.0.1',
                    port:     (int) ($config['port'] ?? 3306),
                    database: $config['database'],
                    username: $config['username'],
                    password: $config['password'],
                    extras:   ['driver' => $config['driver']],
                ),
            );
        });
    }
}
```

Usage in an Artisan command:

```php
use SQLCraft\DatabaseSession;

class InspectSchema extends Command
{
    public function __construct(private readonly DatabaseSession $db)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->db->schema()->listTables() as $table) {
            $this->line($table->name);
        }
        return 0;
    }
}
```

## Symfony services.yaml / Bundle

Symfony's DI container auto-wires PSR interfaces when implementations are registered.
SQLCraft accepts `EventDispatcherInterface`, `CacheInterface` (PSR-16), and the
metadata cache wrapper.

```yaml
# config/services.yaml
services:
    SQLCraft\Schema\Psr16MetadataCache:
        arguments:
            $cache: '@cache.app'
            $ttl:   300

    SQLCraft\SQLCraftFactory:
        arguments:
            $events: '@event_dispatcher'   # Psr\EventDispatcher\EventDispatcherInterface
            $cache:  '@SQLCraft\Schema\Psr16MetadataCache'

    SQLCraft\DatabaseSession:
        factory: ['@SQLCraft\SQLCraftFactory', 'session']
        arguments:
            $parameters: '@sqlcraft.default_parameters'
```

Define a parameter object as a service or in a factory class:

```php
// src/SQLCraft/DefaultConnectionParametersFactory.php
use SQLCraft\ValueObjects\ConnectionParameters;

final class DefaultConnectionParametersFactory
{
    public function create(string $dsn): ConnectionParameters
    {
        return ConnectionParameters::fromDsn($dsn);
    }
}
```

If you use the Symfony EventDispatcher, SQLCraft events implement
`Psr\EventDispatcher\StoppableEventInterface` indirectly through the `SQLCraftEventInterface`
contract, so you can listen to them with the standard Symfony subscriber:

```php
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use SQLCraft\Events\AfterQueryExecuted;

final class SlowQuerySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [AfterQueryExecuted::class => 'onQueryExecuted'];
    }

    public function onQueryExecuted(AfterQueryExecuted $event): void
    {
        if ($event->durationMs > 500) {
            // log slow query
        }
    }
}
```

PSR-3 logging works via your existing Monolog setup — pass a `LoggerInterface` to any service
that accepts one, or use Symfony's autowiring:

```yaml
    SQLCraft\Execution\QueryExecutor:
        arguments:
            $logger: '@logger'
```

## Slim Framework

Slim uses any PSR-11 container. Register with PHP-DI:

```php
// config/dependencies.php
use DI\ContainerBuilder;
use SQLCraft\SQLCraftFactory;
use SQLCraft\Schema\Psr16MetadataCache;

$builder = new ContainerBuilder();
$builder->addDefinitions([
    SQLCraftFactory::class => \DI\factory(function () {
        return new SQLCraftFactory(
            cache: new Psr16MetadataCache(
                new \Symfony\Component\Cache\Adapter\ArrayAdapter()
            ),
        );
    }),
]);
$container = $builder->build();

$app = \Slim\Factory\AppFactory::createFromContainer($container);
```

Route handler usage:

```php
$app->get('/schema', function ($request, $response, $args) {
    $session = $this->get(SQLCraftFactory::class)->session(
        ConnectionParameters::fromEnv()
    );
    $tables = $session->schema()->listTables()->toArray();
    $response->getBody()->write(json_encode($tables));
    return $response->withHeader('Content-Type', 'application/json');
});
```

## Laminas

Register in `module.config.php`:

```php
// module/Application/config/module.config.php
use Laminas\ServiceManager\Factory\InvokableFactory;
use SQLCraft\SQLCraftFactory;

return [
    'service_manager' => [
        'factories' => [
            SQLCraftFactory::class => static function ($container): SQLCraftFactory {
                $config = $container->get('config')['sqlcraft'] ?? [];
                return new SQLCraftFactory(
                    events: $container->has(\Psr\EventDispatcher\EventDispatcherInterface::class)
                        ? $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class)
                        : null,
                );
            },
        ],
    ],
    'sqlcraft' => [
        'driver'   => 'pgsql',
        'host'     => '127.0.0.1',
        'database' => 'myapp',
    ],
];
```

## Testing: Injecting Test Doubles

Because `SQLCraftFactory` depends on `PlatformInterface`, `SchemaManagerInterface`, and
`DdlManager` through constructors, you can inject mocks at every level.

### Mocking SchemaManagerInterface

```php
use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Schema\SchemaManagerInterface;
use SQLCraft\Collections\TableCollection;
use SQLCraft\DTO\TableStatus;

class MyServiceTest extends TestCase
{
    public function testListsOnlyUserTables(): void
    {
        $schema = $this->createMock(SchemaManagerInterface::class);
        $schema->method('listTables')->willReturn(
            new TableCollection([
                new TableStatus(name: 'users', type: 'BASE TABLE'),
                new TableStatus(name: 'orders', type: 'BASE TABLE'),
            ])
        );

        $service = new MySchemaService($schema);
        self::assertCount(2, $service->userTables());
    }
}
```

### Mocking DdlManager

```php
use SQLCraft\DDL\DdlManager;

$ddl = $this->createMock(DdlManager::class);
$ddl->expects($this->once())->method('dropTable');

$migrator = new SchemaMigrator($ddl);
$migrator->dropLegacyTables();
```

### Full session double

```php
use SQLCraft\DatabaseSession;

$session = $this->createMock(DatabaseSession::class);
$session->method('schema')->willReturn($mockSchema);
$session->method('ddl')->willReturn($mockDdl);
```

## Environment Variable Patterns

`EnvCredentialProvider` resolves credentials from environment variables at session creation
time. By default it reads `SQLCRAFT_USERNAME` and `SQLCRAFT_PASSWORD`, but you can pass a
custom key prefix or use `ArrayCredentialProvider` and `CallbackCredentialProvider` for more
control.

```php
use SQLCraft\Connection\ArrayCredentialProvider;
use SQLCraft\ValueObjects\Credential;

$creds = new ArrayCredentialProvider([
    'primary' => new Credential('app_user', 'app_pass'),
    'readonly' => new Credential('ro_user', 'ro_pass'),
]);

$factory = new SQLCraftFactory(credentials: $creds);
$session = $factory->session($params, credentialKey: 'readonly');
```

## Multi-Tenant Usage

One `SQLCraftFactory` instance can open sessions to multiple databases or tenants.
The factory is stateless aside from its driver registry and credential provider.

```php
final class TenantSessionPool
{
    /** @var array<string, DatabaseSession> */
    private array $sessions = [];

    public function __construct(private readonly SQLCraftFactory $factory) {}

    public function get(string $tenantId): DatabaseSession
    {
        return $this->sessions[$tenantId] ??= $this->factory->session(
            new ConnectionParameters(
                host:     '10.0.0.' . $this->resolveHost($tenantId),
                port:     5432,
                database: 'tenant_' . $tenantId,
                extras:   ['driver' => 'pgsql'],
            ),
            name: 'tenant_' . $tenantId,
            credentialKey: $tenantId,
        );
    }
}
```

All open connections are tracked by `SQLCraftFactory::connections()` (returns a
`ConnectionManager`), so you can iterate or close them on shutdown.
