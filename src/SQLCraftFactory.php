<?php

declare(strict_types=1);

namespace SQLCraft;

use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Connection\ConnectionManager;
use SQLCraft\Contracts\Connection\ConnectionInitializerInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;
use SQLCraft\Contracts\Execution\QueryInterceptorInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Import\FormatReaderInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Contracts\Metadata\PrivilegeInspectorInterface;
use SQLCraft\DDL\DdlManager;
use SQLCraft\Driver\DriverRegistry;
use SQLCraft\Events\ConnectionInitializationFailedEvent;
use SQLCraft\Events\SchemaEventDispatcher;
use SQLCraft\Exceptions\ConnectionInitializationException;
use SQLCraft\Exceptions\CredentialNotFoundException;
use SQLCraft\Exceptions\DriverMisconfiguredException;
use SQLCraft\Exceptions\ExtensionConfigurationException;
use SQLCraft\Exceptions\OperationCancelledException;
use SQLCraft\Execution\BatchExecutor;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Execution\QueryInterceptorPipeline;
use SQLCraft\Export\Exporter;
use SQLCraft\Export\FormatRegistry;
use SQLCraft\Import\CsvImporter;
use SQLCraft\Import\Importer;
use SQLCraft\Metadata\MetadataInspectorSet;
use SQLCraft\Query\StatementSplitter;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\Security\DenySecurityGuard;
use SQLCraft\Security\PrivilegeGuard;
use SQLCraft\Security\PrivilegeManager;
use SQLCraft\Security\UserManager;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Credential;

/** @internal */
final class SQLCraftFactory
{
    private ConnectionManager $connections;

    /**
     * @param  list<ConnectionInitializerInterface>  $initializers
     * @param  list<QueryInterceptorInterface>  $interceptors
     * @param  list<\Closure>  $metadataDecorators
     * @param  array<string, \Closure(ConnectionInterface): FormatWriterInterface>  $writerFactories
     * @param  array<string, \Closure(): FormatReaderInterface>  $readerFactories
     */
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly CredentialProviderInterface $credentials,
        private readonly EventDispatcherInterface $events,
        private readonly ConnectionEventDispatcherInterface $connectionEvents,
        private readonly ?QueryHistoryInterface $history,
        private readonly MetadataCacheInterface $cache,
        private readonly array $initializers,
        private readonly array $interceptors,
        private readonly array $metadataDecorators,
        private readonly array $writerFactories,
        private readonly array $readerFactories,
    ) {
        $this->connections = new ConnectionManager();
    }

    public function session(ConnectionParameters $parameters, ?string $name = null, ?string $credentialKey = null): DatabaseSession
    {
        $effective = $parameters;
        if ($credentialKey !== null) {
            $provider = $this->credentials;
            $credential = $provider->resolve($credentialKey);
            if (! $credential instanceof Credential) {
                throw new CredentialNotFoundException('Credential was not found.', $parameters->host ?? '', (string) $parameters->driver);
            }
            $effective = new ConnectionParameters(
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

        $driverName = $effective->driver;
        if ($driverName === null) {
            throw new \InvalidArgumentException('ConnectionParameters::$driver must be set when using SQLCraftFactory::session(). Pass a DatabaseDriver enum case, e.g. driver: DatabaseDriver::SQLite.');
        }
        $runtime = $this->drivers->getRegistered($driverName);
        $canonical = $runtime->driver->getName();
        $connectionName = $this->normalizeConnectionName($name ?? $driverName);
        if ($this->connections->has($connectionName)) {
            throw new \InvalidArgumentException("Connection already exists: $connectionName.");
        }

        $events = $this->connectionEvents;
        $reason = $events->beforeConnectionOpened($connectionName, $effective);
        if ($reason !== null) {
            throw new OperationCancelledException($reason);
        }

        $started = hrtime(true);
        $connection = null;
        try {
            $connection = $this->connectDriver($runtime->driver, $effective, $connectionName);
            if ($connection->getPlatformName() !== $canonical) {
                throw new DriverMisconfiguredException("Connected platform does not match driver: $canonical.", $canonical);
            }
            foreach ($this->initializers as $initializer) {
                $initializer->initialize($connection, $effective);
            }
            $this->connections->add($connectionName, $connection);
            $events->connectionOpened($connectionName, $canonical, $effective->host, $effective->database, (hrtime(true) - $started) / 1_000_000, $connection);
        } catch (\Throwable $error) {
            if ($connection instanceof ConnectionInterface) {
                $connection->close();
            }
            if ($error instanceof OperationCancelledException) {
                throw $error;
            }
            $notification = null;
            try {
                $this->events->dispatch(new ConnectionInitializationFailedEvent($connectionName, $canonical, $effective->host, $effective->database, $error));
            } catch (\Throwable $notificationError) {
                $notification = $notificationError;
            }
            throw new ConnectionInitializationException('Connection initialization failed.', $effective->host ?? '', $canonical, $error, $notification);
        }

        $inspectors = $runtime->metadata->create($connection);
        foreach ($this->metadataDecorators as $decorator) {
            $decorated = $decorator($inspectors, $connection);
            if (! $decorated instanceof MetadataInspectorSet) {
                throw new ExtensionConfigurationException('Metadata decorator must return MetadataInspectorSet.');
            }
            $inspectors = $decorated;
        }

        $coreEvents = $this->events;
        $schemaEvents = new SchemaEventDispatcher($coreEvents);
        $schema = SchemaManagerFactory::schemaManager($inspectors, $this->cache, $schemaEvents);
        $source = SchemaManagerFactory::exportSource($inspectors);
        $pipeline = new QueryInterceptorPipeline($this->interceptors);
        $executor = new QueryExecutor($this->history, $this->events, 1000, $pipeline);
        $formats = new FormatRegistry($connection);
        foreach ($this->writerFactories as $format => $factory) {
            $formats->registerWriterFactory($format, $factory);
        }
        foreach ($this->readerFactories as $format => $factory) {
            $formats->registerReaderFactory($format, $factory);
        }
        $exporter = new Exporter($source, $executor, $formats);
        $csv = new CsvImporter($inspectors->column(), $executor);
        $importer = new Importer(new StatementSplitter(), new BatchExecutor($executor));
        $process = $runtime->processes?->create($connection, $inspectors->server(), $executor);

        return new DatabaseSession(
            $connection,
            $schema,
            new DdlManager($executor, events: $schemaEvents),
            $executor,
            $exporter,
            $importer,
            $inspectors->privileges() instanceof PrivilegeInspectorInterface ? new PrivilegeGuard($connection, $inspectors->privileges()) : new DenySecurityGuard(),
            new UserManager($connection, $executor),
            new PrivilegeManager($connection, $executor),
            $formats,
            $csv,
            $process,
        );
    }

    public function connections(): ConnectionManager
    {
        return $this->connections;
    }

    private function normalizeConnectionName(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '' || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1) {
            throw new \InvalidArgumentException('Connection name must not be blank or contain control characters.');
        }

        return $normalized;
    }

    private function connectDriver(DriverInterface $driver, ConnectionParameters $parameters, string $name): ConnectionInterface
    {
        return $driver->connect($parameters, $name);
    }
}
