<?php

declare(strict_types=1);

namespace SQLCraft;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Connection\EnvCredentialProvider;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInitializerInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;
use SQLCraft\Contracts\Execution\QueryInterceptorInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Import\FormatReaderInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Driver\DriverDefinition;
use SQLCraft\Driver\DriverRegistry;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Driver\PostgreSQLDriver;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Driver\SqlServerDriver;
use SQLCraft\Events\AfterDdlExecuted;
use SQLCraft\Events\CompositeEventDispatcher;
use SQLCraft\Events\ConnectionEventDispatcher;
use SQLCraft\Events\SchemaChangedEvent;
use SQLCraft\Events\SimpleEventDispatcher;
use SQLCraft\Events\SimpleListenerProvider;
use SQLCraft\Exceptions\DuplicateRegistrationException;
use SQLCraft\Exceptions\ExtensionConfigurationException;
use SQLCraft\Exceptions\RegistrationNotFoundException;
use SQLCraft\Execution\MySQLProcessManagerFactory;
use SQLCraft\Execution\PostgreSQLProcessManagerFactory;
use SQLCraft\Execution\SqlServerProcessManagerFactory;
use SQLCraft\Export\CsvFormatWriter;
use SQLCraft\Export\CsvSemicolonFormatWriter;
use SQLCraft\Export\HtmlFormatWriter;
use SQLCraft\Export\JsonFormatWriter;
use SQLCraft\Export\SqlFormatWriter;
use SQLCraft\Export\TsvFormatWriter;
use SQLCraft\Export\XlsxFormatWriter;
use SQLCraft\Export\XmlFormatWriter;
use SQLCraft\Import\CsvFormatReader;
use SQLCraft\Metadata\DefaultMetadataInspectorSetFactory;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\Metadata\PostgreSQLMetadataFactory;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\Metadata\SqlServerMetadataFactory;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\Schema\CacheInvalidationListener;
use SQLCraft\Schema\NullMetadataCache;
use SQLCraft\Support\ExtensionIdentifier;

final class SQLCraftBuilder
{
    /** @var array<string, DriverDefinition> */
    private array $drivers = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, Closure(ConnectionInterface): FormatWriterInterface> */
    private array $writers = [];

    /** @var array<string, Closure(): FormatReaderInterface> */
    private array $readers = [];

    /** @var list<ConnectionInitializerInterface> */
    private array $initializers = [];

    /** @var list<QueryInterceptorInterface> */
    private array $interceptors = [];

    /** @var list<Closure> */
    private array $decorators = [];

    /** @var list<array{0: string, 1: callable, 2: int}> */
    private array $listeners = [];

    private ?CredentialProviderInterface $credentials = null;

    private ?QueryHistoryInterface $history = null;

    private ?MetadataCacheInterface $cache = null;

    private ?EventDispatcherInterface $external = null;

    public static function defaults(): self
    {
        $builder = new self;
        $pdoFactory = static fn (ConnectionEventDispatcherInterface $events): PdoConnectionFactory => new PdoConnectionFactory(new PdoExceptionTranslator, $events, false);

        $builder
            ->registerDriver(new DriverDefinition('mysql', static fn ($events): MySQLDriver => new MySQLDriver($pdoFactory($events), new MySQLPlatform), new DefaultMetadataInspectorSetFactory(new MySQLMetadataFactory), new MySQLProcessManagerFactory))
            ->registerDriver(new DriverDefinition('pgsql', static fn ($events): PostgreSQLDriver => new PostgreSQLDriver($pdoFactory($events), new PostgreSQLPlatform), new DefaultMetadataInspectorSetFactory(new PostgreSQLMetadataFactory), new PostgreSQLProcessManagerFactory))
            ->registerDriver(new DriverDefinition('sqlite', static fn ($events): SqliteDriver => new SqliteDriver($pdoFactory($events), new SqlitePlatform), new DefaultMetadataInspectorSetFactory(new SqliteMetadataFactory)))
            ->registerDriver(new DriverDefinition('sqlserver', static fn ($events): SqlServerDriver => new SqlServerDriver($pdoFactory($events), new SqlServerPlatform), new DefaultMetadataInspectorSetFactory(new SqlServerMetadataFactory), new SqlServerProcessManagerFactory))
            ->registerDriverAlias('mariadb', 'mysql')
            ->registerWriter('sql', static fn (ConnectionInterface $connection): FormatWriterInterface => new SqlFormatWriter($connection))
            ->registerWriter('csv', static fn (ConnectionInterface $connection): FormatWriterInterface => new CsvFormatWriter)
            ->registerWriter('tsv', static fn (ConnectionInterface $connection): FormatWriterInterface => new TsvFormatWriter)
            ->registerWriter('csv-semicolon', static fn (ConnectionInterface $connection): FormatWriterInterface => new CsvSemicolonFormatWriter)
            ->registerWriter('json', static fn (ConnectionInterface $connection): FormatWriterInterface => new JsonFormatWriter)
            ->registerWriter('xml', static fn (ConnectionInterface $connection): FormatWriterInterface => new XmlFormatWriter)
            ->registerWriter('xlsx', static fn (ConnectionInterface $connection): FormatWriterInterface => new XlsxFormatWriter)
            ->registerWriter('html', static fn (ConnectionInterface $connection): FormatWriterInterface => new HtmlFormatWriter)
            ->registerReader('csv', static fn (): FormatReaderInterface => new CsvFormatReader)
            ->credentials(new EnvCredentialProvider)
            ->metadataCache(null);

        return $builder;
    }

    public function registerDriver(DriverDefinition $definition): self
    {
        $name = ExtensionIdentifier::normalize($definition->name, 'driver');
        if (isset($this->drivers[$name]) || isset($this->aliases[$name])) {
            throw new DuplicateRegistrationException("Driver already registered: $name.");
        }
        $this->drivers[$name] = $definition;

        return $this;
    }

    public function replaceDriver(DriverDefinition $definition): self
    {
        $name = ExtensionIdentifier::normalize($definition->name, 'driver');
        if (! isset($this->drivers[$name])) {
            throw new RegistrationNotFoundException("Driver is not registered: $name.");
        }
        $this->drivers[$name] = $definition;

        return $this;
    }

    public function registerDriverAlias(string $alias, string $target): self
    {
        $alias = ExtensionIdentifier::normalize($alias, 'driver alias');
        $target = ExtensionIdentifier::normalize($target, 'driver');
        if (isset($this->aliases[$alias]) || isset($this->drivers[$alias])) {
            throw new DuplicateRegistrationException("Driver alias already registered: $alias.");
        }
        $this->aliases[$alias] = $target;

        return $this;
    }

    public function replaceDriverAlias(string $alias, string $target): self
    {
        $alias = ExtensionIdentifier::normalize($alias, 'driver alias');
        if (! isset($this->aliases[$alias])) {
            throw new RegistrationNotFoundException("Driver alias is not registered: $alias.");
        }
        $this->aliases[$alias] = ExtensionIdentifier::normalize($target, 'driver');

        return $this;
    }

    /** @param Closure(ConnectionInterface): FormatWriterInterface $factory */
    public function registerWriter(string $format, Closure $factory): self
    {
        $format = ExtensionIdentifier::normalize($format, 'writer');
        if (isset($this->writers[$format])) {
            throw new DuplicateRegistrationException("Writer already registered: $format.");
        }
        $this->writers[$format] = $factory;

        return $this;
    }

    /** @param Closure(ConnectionInterface): FormatWriterInterface $factory */
    public function replaceWriter(string $format, Closure $factory): self
    {
        $format = ExtensionIdentifier::normalize($format, 'writer');
        if (! isset($this->writers[$format])) {
            throw new RegistrationNotFoundException("Writer is not registered: $format.");
        }
        $this->writers[$format] = $factory;

        return $this;
    }

    /** @param Closure(): FormatReaderInterface $factory */
    public function registerReader(string $format, Closure $factory): self
    {
        $format = ExtensionIdentifier::normalize($format, 'reader');
        if (isset($this->readers[$format])) {
            throw new DuplicateRegistrationException("Reader already registered: $format.");
        }
        $this->readers[$format] = $factory;

        return $this;
    }

    /** @param Closure(): FormatReaderInterface $factory */
    public function replaceReader(string $format, Closure $factory): self
    {
        $format = ExtensionIdentifier::normalize($format, 'reader');
        if (! isset($this->readers[$format])) {
            throw new RegistrationNotFoundException("Reader is not registered: $format.");
        }
        $this->readers[$format] = $factory;

        return $this;
    }

    public function credentials(CredentialProviderInterface $provider): self
    {
        $this->credentials = $provider;

        return $this;
    }

    public function queryHistory(?QueryHistoryInterface $history): self
    {
        $this->history = $history;

        return $this;
    }

    public function metadataCache(?MetadataCacheInterface $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    public function initializeConnection(ConnectionInitializerInterface $initializer): self
    {
        $this->initializers[] = $initializer;

        return $this;
    }

    public function interceptQueries(QueryInterceptorInterface $interceptor): self
    {
        $this->interceptors[] = $interceptor;

        return $this;
    }

    public function decorateMetadataInspectors(Closure $decorator): self
    {
        $this->decorators[] = $decorator;

        return $this;
    }

    public function listen(string $eventClass, callable $listener, int $priority = 0): self
    {
        if ($this->external instanceof EventDispatcherInterface) {
            throw new ExtensionConfigurationException('SQLCraft-owned listeners and external dispatcher are mutually exclusive.');
        }
        if (! class_exists($eventClass) && ! interface_exists($eventClass)) {
            throw new ExtensionConfigurationException("Event class is not loadable: $eventClass.");
        }
        /** @var class-string $eventClass */
        $this->listeners[] = [$eventClass, $listener, $priority];

        return $this;
    }

    public function eventDispatcher(EventDispatcherInterface $dispatcher): self
    {
        if ($this->external instanceof EventDispatcherInterface || $this->listeners !== []) {
            throw new ExtensionConfigurationException('SQLCraft-owned listeners and external dispatcher are mutually exclusive.');
        }
        $this->external = $dispatcher;

        return $this;
    }

    public function build(): SQLCraftFactory
    {
        if ($this->drivers === []) {
            throw new ExtensionConfigurationException('At least one driver must be registered.');
        }
        foreach ($this->aliases as $target) {
            if (! isset($this->drivers[$target])) {
                throw new ExtensionConfigurationException("Driver alias target is not registered: $target.");
            }
        }

        $coreProvider = new SimpleListenerProvider;
        $cache = $this->cache ?? new NullMetadataCache;
        $cacheListener = new CacheInvalidationListener($cache);
        $coreProvider->listen(AfterDdlExecuted::class, $cacheListener(...));
        $coreProvider->listen(SchemaChangedEvent::class, $cacheListener(...));
        $core = new SimpleEventDispatcher($coreProvider);
        $user = null;
        if ($this->listeners !== []) {
            $provider = new SimpleListenerProvider;
            foreach ($this->listeners as [$eventClass, $listener, $priority]) {
                /** @var class-string $listenerClass */
                $listenerClass = $eventClass;
                $provider->listen($listenerClass, $listener, $priority);
            }
            $user = new SimpleEventDispatcher($provider);
        }
        $events = new CompositeEventDispatcher($core, $user ?? $this->external);
        $connectionEvents = new ConnectionEventDispatcher($events);
        $registry = new DriverRegistry;
        foreach ($this->drivers as $definition) {
            $registry->registerDefinition($definition, $connectionEvents);
        }
        foreach ($this->aliases as $alias => $target) {
            $registry->registerAlias($alias, $target);
        }

        return new SQLCraftFactory(
            $registry,
            $this->credentials ?? new EnvCredentialProvider,
            $events,
            $connectionEvents,
            $this->history,
            $cache,
            $this->initializers,
            $this->interceptors,
            $this->decorators,
            $this->writers,
            $this->readers,
        );
    }
}
