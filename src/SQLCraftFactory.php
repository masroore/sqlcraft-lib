<?php
declare(strict_types=1);
namespace SQLCraft;
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Connection\{ConnectionManager,CredentialProviderChain};
use SQLCraft\Contracts\Connection\{ConnectionInitializerInterface,ConnectionManagerInterface,CredentialProviderInterface};
use SQLCraft\Contracts\Execution\{ProcessManagerFactoryInterface,QueryInterceptorInterface,QueryHistoryInterface};
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Driver\DriverRegistry;
use SQLCraft\Events\{ConnectionEventDispatcher,ConnectionInitializationFailedEvent,SchemaEventDispatcher};
use SQLCraft\Execution\{BatchExecutor,QueryExecutor,QueryInterceptorPipeline};
use SQLCraft\Export\{Exporter,FormatRegistry};
use SQLCraft\Import\{CsvImporter,Importer};
use SQLCraft\Metadata\MetadataInspectorSet;
use SQLCraft\Query\StatementSplitter;
use SQLCraft\Schema\{NullMetadataCache,SchemaManagerFactory};
use SQLCraft\Security\{PrivilegeGuard,PrivilegeManager,UserManager};
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\Exceptions\{ConnectionInitializationException,CredentialNotFoundException,DriverMisconfiguredException};
final class SQLCraftFactory
{
    private ConnectionManager $connections;
    /** @param list<ConnectionInitializerInterface> $initializers @param list<QueryInterceptorInterface> $interceptors @param list<\Closure(MetadataInspectorSet, \SQLCraft\Contracts\Connection\ConnectionInterface): MetadataInspectorSet> $metadataDecorators @param array<string, \Closure(\SQLCraft\Contracts\Connection\ConnectionInterface): \SQLCraft\Contracts\Export\FormatWriterInterface> $writerFactories @param array<string, \Closure(): \SQLCraft\Contracts\Import\FormatReaderInterface> $readerFactories */
    public function __construct(private readonly DriverRegistry $drivers = new DriverRegistry, private readonly ?CredentialProviderInterface $credentials = null, private readonly ?EventDispatcherInterface $events = null, private readonly ?ConnectionEventDispatcher $connectionEvents = null, private readonly ?QueryHistoryInterface $history = null, private readonly ?MetadataCacheInterface $cache = null, private readonly array $initializers = [], private readonly array $interceptors = [], private readonly array $metadataDecorators = [], private readonly array $writerFactories = [], private readonly array $readerFactories = []) { $this->connections=new ConnectionManager; }
    public function session(ConnectionParameters $parameters, ?string $name=null, ?string $credentialKey=null): DatabaseSession
    {
        $effective=$parameters; if($credentialKey!==null){$credential=($this->credentials??throw new CredentialNotFoundException('Credential provider is not configured.'))->resolve($credentialKey); if($credential===null) throw new CredentialNotFoundException('Credential was not found.', $parameters->host??'', (string)$parameters->driver); $effective=new ConnectionParameters($parameters->host,$parameters->port,$parameters->socket,$parameters->database,$credential->username,$credential->password,$parameters->charset,$parameters->ssl,$parameters->extras,$parameters->driver);}
        $driverName=$effective->driver; if($driverName===null) throw new \InvalidArgumentException('ConnectionParameters::$driver must be set when using SQLCraftFactory::session(). Pass a DatabaseDriver enum case, e.g. driver: DatabaseDriver::SQLite.'); $runtime=$this->drivers->getRegistered($driverName); $canonical=$runtime->driver->getName(); $connectionName=$name??$driverName; if($this->connections->has($connectionName)) throw new \InvalidArgumentException("Connection already exists: $connectionName.");
        $events=$this->connectionEvents; if($events instanceof ConnectionEventDispatcher){$reason=$events->beforeConnectionOpened($connectionName,$effective); if($reason!==null) throw new \SQLCraft\Exceptions\OperationCancelledException($reason);}
        $started=hrtime(true); $connection=null;
        try { $connection=$this->connectDriver($runtime->driver,$effective,$connectionName); if($connection->getPlatformName()!==$canonical) throw new DriverMisconfiguredException("Connected platform does not match driver: $canonical.",$canonical); foreach($this->initializers as $initializer) $initializer->initialize($connection,$effective); $this->connections->add($connectionName,$connection); $events?->connectionOpened($connectionName,$canonical,$effective->host,$effective->database,(hrtime(true)-$started)/1_000_000,$connection); } catch(\Throwable $error){ if($connection) $connection->close(); if($error instanceof \SQLCraft\Exceptions\OperationCancelledException) throw $error; $notification=null; try{$this->events?->dispatch(new ConnectionInitializationFailedEvent($connectionName,$canonical,$effective->host,$effective->database,$error));}catch(\Throwable $e){$notification=$e;} throw new ConnectionInitializationException('Connection initialization failed.', $effective->host??'', $canonical, $error, $notification); }
        $inspectors=$runtime->metadata->create($connection); foreach($this->metadataDecorators as $decorator){$inspectors=$decorator($inspectors,$connection); if(!$inspectors instanceof MetadataInspectorSet) throw new \SQLCraft\Exceptions\ExtensionConfigurationException('Metadata decorator must return MetadataInspectorSet.');}
        $schemaEvents=new SchemaEventDispatcher($this->events??new \SQLCraft\Events\SimpleEventDispatcher(new \SQLCraft\Events\SimpleListenerProvider)); $schema=SchemaManagerFactory::schemaManager($inspectors,$this->cache??new NullMetadataCache,$schemaEvents); $source=SchemaManagerFactory::exportSource($inspectors); $pipeline=new QueryInterceptorPipeline($this->interceptors); $executor=new QueryExecutor($this->history,$this->events,1000,$pipeline); $formats=new FormatRegistry($connection); foreach($this->writerFactories as $format=>$factory)$formats->registerWriterFactory($format,$factory); foreach($this->readerFactories as $format=>$factory)$formats->registerReaderFactory($format,$factory); $exporter=new Exporter($source,$executor,$formats); $csv=new CsvImporter($inspectors->column(),null,$executor); $importer=new Importer(new StatementSplitter,new BatchExecutor($executor)); $process=null; if($runtime->processes)$process=$runtime->processes->create($connection,$inspectors->server(),$executor);
        return new DatabaseSession($connection,$schema,new \SQLCraft\DDL\DdlManager($executor,events:$schemaEvents),$executor,$exporter,$importer,new PrivilegeGuard($connection,$inspectors->privileges()??new \SQLCraft\Metadata\PrivilegeInspector),new UserManager($connection,$executor),new PrivilegeManager($connection,$executor),$formats,$csv,$process);
    }
    private function connectDriver(\SQLCraft\Contracts\Driver\DriverInterface $driver, ConnectionParameters $parameters, string $name): \SQLCraft\Contracts\Connection\ConnectionInterface
    { $method=new \ReflectionMethod($driver,'connect'); return $method->getNumberOfParameters()>=2 ? $driver->connect($parameters,$name) : $driver->connect($parameters); }
    public function connections(): ConnectionManager { return $this->connections; }
}
