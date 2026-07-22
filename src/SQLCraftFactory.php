<?php

declare(strict_types=1);

namespace SQLCraft;

use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Connection\ConnectionManager;
use SQLCraft\Connection\EnvCredentialProvider;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\DDL\DdlManager;
use SQLCraft\Driver\DriverRegistry;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Driver\PostgreSQLDriver;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Driver\SqlServerDriver;
use SQLCraft\Events\AfterDdlExecuted;
use SQLCraft\Events\ConnectionEventDispatcher;
use SQLCraft\Events\SchemaChangedEvent;
use SQLCraft\Events\SchemaEventDispatcher;
use SQLCraft\Events\SimpleEventDispatcher;
use SQLCraft\Events\SimpleListenerProvider;
use SQLCraft\Execution\BatchExecutor;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Export\CsvFormatWriter;
use SQLCraft\Export\CsvSemicolonFormatWriter;
use SQLCraft\Export\Exporter;
use SQLCraft\Export\FormatRegistry;
use SQLCraft\Export\SqlFormatWriter;
use SQLCraft\Export\TsvFormatWriter;
use SQLCraft\Import\CsvFormatReader;
use SQLCraft\Import\Importer;
use SQLCraft\Metadata\PrivilegeInspector;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\Query\StatementSplitter;
use SQLCraft\Schema\CacheInvalidationListener;
use SQLCraft\Schema\NullMetadataCache;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\Security\PrivilegeGuard;
use SQLCraft\Security\PrivilegeManager;
use SQLCraft\Security\UserManager;
use SQLCraft\ValueObjects\ConnectionParameters;

final class SQLCraftFactory
{
    private readonly DriverRegistry $drivers;

    private readonly CredentialProviderInterface $credentials;

    private readonly EventDispatcherInterface $events;

    private readonly ConnectionManager $connections;

    private readonly ?MetadataCacheInterface $cache;

    public function __construct(
        ?DriverRegistry $drivers = null,
        ?CredentialProviderInterface $credentials = null,
        ?EventDispatcherInterface $events = null,
        ?MetadataCacheInterface $cache = null,
    ) {
        $pdo = new PdoConnectionFactory(new PdoExceptionTranslator, $events instanceof EventDispatcherInterface ? new ConnectionEventDispatcher($events) : null);
        $this->drivers = $drivers ?? new DriverRegistry([
            new MySQLDriver($pdo, new MySQLPlatform),
            new PostgreSQLDriver($pdo, new PostgreSQLPlatform),
            new SqliteDriver($pdo, new SqlitePlatform),
            new SqlServerDriver($pdo, new SqlServerPlatform),
        ]);
        if (! $drivers instanceof DriverRegistry) {
            $this->drivers->registerAlias('mariadb', $this->drivers->get('mysql'));
        }
        $this->credentials = $credentials ?? new EnvCredentialProvider;
        $this->connections = new ConnectionManager;
        $this->cache = $cache;

        if (! $events instanceof EventDispatcherInterface) {
            $provider = new SimpleListenerProvider;
            $this->events = new SimpleEventDispatcher($provider);
            if ($cache instanceof MetadataCacheInterface) {
                $listener = new CacheInvalidationListener($cache);
                $provider->listen(AfterDdlExecuted::class, $listener);
                $provider->listen(SchemaChangedEvent::class, $listener);
            }
        } else {
            $this->events = $events;
        }
    }

    public function session(ConnectionParameters $parameters, ?string $name = null, ?string $credentialKey = null): DatabaseSession
    {
        if ($credentialKey !== null) {
            $credential = $this->credentials->resolve($credentialKey);
            $parameters = new ConnectionParameters(
                host: $parameters->host,
                port: $parameters->port,
                socket: $parameters->socket,
                database: $parameters->database,
                username: $credential->username,
                password: $credential->password,
                charset: $parameters->charset,
                ssl: $parameters->ssl,
                extras: $parameters->extras,
                driver: $parameters->driver,
            );
        }

        if ($parameters->driver === null) {
            throw new \InvalidArgumentException(
                'ConnectionParameters::$driver must be set when using SQLCraftFactory::session(). '
                . 'Pass a DatabaseDriver enum case, e.g. driver: DatabaseDriver::SQLite.'
            );
        }
        $connection = $this->drivers->getByDriver($parameters->driver)->connect($parameters);
        $connectionName = $name ?? $connection->getName() ?? $parameters->driver->value;
        $this->connections->add($connectionName, $connection);

        $queryExecutor = new QueryExecutor(events: $this->events);
        $schemaEvents = new SchemaEventDispatcher($this->events);
        $schema = SchemaManagerFactory::forConnection($connection, $this->cache ?? new NullMetadataCache, $schemaEvents);
        $source = SchemaManagerFactory::exportSourceForConnection($connection);
        $registry = new FormatRegistry([
            new SqlFormatWriter($connection),
            new CsvFormatWriter,
            new TsvFormatWriter,
            new CsvSemicolonFormatWriter,
        ], [new CsvFormatReader]);
        $exporter = new Exporter($source, $queryExecutor, $registry);
        $importer = new Importer(new StatementSplitter, new BatchExecutor($queryExecutor));

        return new DatabaseSession(
            $connection,
            $schema,
            new DdlManager($queryExecutor, events: $schemaEvents),
            $queryExecutor,
            $exporter,
            $importer,
            new PrivilegeGuard($connection, new PrivilegeInspector),
            new UserManager($connection, $queryExecutor),
            new PrivilegeManager($connection, $queryExecutor),
        );
    }

    public function connections(): ConnectionManager
    {
        return $this->connections;
    }
}
