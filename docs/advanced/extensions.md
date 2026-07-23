# Extension author guide

SQLCraft extensions are configured through `SQLCraftBuilder`. The builder is mutable
only during bootstrap; `build()` creates an immutable factory snapshot. Extensions do
not scan directories, subclass a plugin base class, or modify SQLCraft source.

## Driver definition and platform roles

A third-party engine supplies one `DriverDefinition`. The definition owns driver
construction, metadata construction, and optional process control. The driver name is
an arbitrary lowercase identifier; it does not need a `DatabaseDriver` enum case.

```php
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\Contracts\Metadata\MetadataInspectorSetFactoryInterface;
use SQLCraft\Driver\DriverDefinition;
use SQLCraft\SQLCraftBuilder;

$builder = SQLCraftBuilder::defaults()
    ->registerDriver(new DriverDefinition(
        name: 'acme-db',
        driverFactory: static function (ConnectionEventDispatcherInterface $events): AcmeDriver {
            return new AcmeDriver($events); // implements DriverInterface
        },
        metadata: new AcmeMetadataInspectorSetFactory, // fresh set per connection
    ))
    ->registerDriverAlias('acme', 'acme-db');

$factory = $builder->build();
$session = $factory->session(new ConnectionParameters(driver: 'acme'));
```

Use `PlatformRoles` and `ComposedPlatform` when replacing one engine role. The
remaining roles stay unchanged. Metadata SQL uses `introspection()`, DDL uses `ddl()`,
query rendering uses `queryDialect()`, quoting uses `quoting()`, and type mapping uses
`types()`.

```php
$roles = $platform->roles()->withQueryDialect(new AcmeQueryDialect);
$platform = new ComposedPlatform(
    name: 'acme-db',
    roles: $roles,
    serverVersion: static fn (ConnectionInterface $connection): ServerVersion => $connection->getServerVersion(),
    capabilities: static fn (ServerVersion $version): CapabilitySet => $baseCapabilities,
);
```

## Metadata decorators

`decorateMetadataInspectors()` receives the fresh set and active connection. Return a
new set with only the role being changed. The same decorated set feeds schema browsing,
export metadata, CSV column lookup, process listing, and privilege security.

```php
$builder->decorateMetadataInspectors(
    static fn (MetadataInspectorSet $set, ConnectionInterface $connection): MetadataInspectorSet
        => $set->withServer(new FilteringServerInspector($set->server())),
);
```

Decorators must not implement `SchemaManager` or create a second inspector graph.

## Credentials and connection initialization

Providers return `Credential|null`; `CredentialProviderChain` selects the first
non-null result and propagates provider exceptions. A miss at the factory boundary is
reported as `CredentialNotFoundException`.

```php
$builder->credentials(new CredentialProviderChain([
    new ArrayCredentialProvider($known),
    new EnvCredentialProvider,
]));

final readonly class SetApplicationName implements ConnectionInitializerInterface
{
    public function initialize(ConnectionInterface $connection, ConnectionParameters $parameters): void
    {
        $connection->execute('SET application_name = ?', ['sqlcraft']);
    }
}

$builder->initializeConnection(new SetApplicationName);
```

Initializers run in registration order before `ConnectionOpenedEvent`. A failing
initializer closes the connection once, does not register it, dispatches the safe
failure event, and preserves the original exception as `getPrevious()`.

## Query interception

Only `QueryInterceptorInterface` transforms SQL or parameters. Pre-query events may
cancel but are read-only. Interceptors run in registration order for query, DML, DDL,
timeout, batch, import, typed-builder, and exporter query paths.

```php
final readonly class AddComment implements QueryInterceptorInterface
{
    public function intercept(QueryRequest $request): QueryRequest
    {
        return $request->withSqlAndParams($request->sql . ' /* acme */', $request->params);
    }
}

$builder->interceptQueries(new AddComment);
```

`QueryRequest::originalSql` never changes. Timeout wrapping occurs before interception;
execution and history receive the final transformed SQL. Empty SQL, mixed parameter key
shapes, and provenance changes are rejected.

## Formats and lifetime

Register factories, not shared mutable adapters. Writer factories receive the active
connection; reader factories receive no connection. Every resolution creates and
validates a fresh adapter whose format name matches the registration.

```php
$builder
    ->registerWriter('acme-json', static fn (ConnectionInterface $connection): FormatWriterInterface
        => new AcmeWriter($connection))
    ->registerReader('acme-json', static fn (): FormatReaderInterface
        => new AcmeReader);

$session->formats()->getWriter('acme-json');
$session->formats()->getReader('acme-json');
```

Sinks are caller-owned. Use `ResourceSink`, `StringBufferSink`, compression, PSR-7, or
multi-file sinks without global registration.

## Events, history, and cache

SQLCraft-owned listeners use `listen()`. Core invariant listeners run first; priorities
and registration order apply only to SQLCraft-owned listeners. Alternatively provide one
external PSR-14 dispatcher with `eventDispatcher()`. The two modes are mutually
exclusive, and SQLCraft does not inspect or mutate an external listener provider.

```php
$builder->listen(AfterQueryExecuted::class, static function (AfterQueryExecuted $event): void {
    $logger->info($event->sql);
});

$builder->queryHistory($history)->metadataCache($cache);
```

History records final SQL for successful and failed statements. The supplied cache is
used by schema operations and invalidated by core schema-change listeners, including
when an external dispatcher is configured.

## Process capability

Check `Capability::Kill` before requesting `session->processes()`. A driver without a
process manager does not advertise the capability and throws a typed
`CapabilityNotSupportedException`; process IDs are strictly positive integers.

## Failure and conflict semantics

Driver, alias, writer, and reader names are normalized and duplicate registration throws.
Use the corresponding `replace*()` method for intentional replacement. Invalid alias
targets, incomplete driver definitions, factory name mismatches, wrong adapter types,
and conflicting event modes fail during bootstrap or adapter resolution before work
starts.
