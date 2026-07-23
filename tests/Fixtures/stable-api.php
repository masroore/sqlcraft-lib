<?php

declare(strict_types=1);
use SQLCraft\Contracts\Connection\ConnectionInitializerInterface;
use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Contracts\Execution\ProcessManagerFactoryInterface;
use SQLCraft\Contracts\Execution\ProcessManagerInterface;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;
use SQLCraft\Contracts\Execution\QueryInterceptorInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\Contracts\Import\FormatReaderInterface;
use SQLCraft\Contracts\Import\ImportSourceInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Contracts\Metadata\MetadataInspectorSetFactoryInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Contracts\Platform\QueryDialectInterface;
use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\Contracts\Platform\TypeMapperInterface;
use SQLCraft\DatabaseSession;
use SQLCraft\Driver\DriverDefinition;
use SQLCraft\Enums\QueryKind;
use SQLCraft\Execution\QueryRequest;
use SQLCraft\Metadata\MetadataInspectorSet;
use SQLCraft\Platform\ComposedPlatform;
use SQLCraft\Platform\PlatformRoles;
use SQLCraft\SQLCraftBuilder;
use SQLCraft\SQLCraftFactory;

/**
 * Stable caller and implementer surface for the extension architecture.
 *
 * This is intentionally explicit. New public APIs require an intentional
 * compatibility review instead of becoming stable by directory placement.
 *
 * @return array<class-string, list<string>>
 */
return [
    SQLCraftBuilder::class => [
        'defaults', 'registerDriver', 'replaceDriver', 'registerDriverAlias',
        'replaceDriverAlias', 'registerWriter', 'replaceWriter', 'registerReader',
        'replaceReader', 'credentials', 'queryHistory', 'metadataCache',
        'initializeConnection', 'interceptQueries', 'decorateMetadataInspectors',
        'listen', 'eventDispatcher', 'build',
    ],
    SQLCraftFactory::class => ['session', 'connections'],
    DatabaseSession::class => [
        'connection', 'query', 'executeBuilder', 'schema', 'ddl', 'security',
        'users', 'privileges', 'export', 'import', 'formats', 'csvImport', 'processes',
    ],
    DriverDefinition::class => [],
    DriverInterface::class => [
        'buildDsn', 'connect', 'getPlatform', 'getName', 'getPdoDriverNames',
    ],
    PlatformInterface::class => [
        'getName', 'getFlavor', 'getServerVersion', 'getCapabilitySet',
        'getDefaultCharset', 'getDefaultCollation', 'supportsSchemas', 'ddl',
        'introspection', 'queryDialect', 'quoting', 'types',
    ],
    DdlDialectInterface::class => [],
    IntrospectionDialectInterface::class => [],
    QueryDialectInterface::class => [],
    QuotingInterface::class => [],
    TypeMapperInterface::class => [],
    PlatformRoles::class => [
        'withDdl', 'withIntrospection', 'withQueryDialect', 'withQuoting', 'withTypes',
    ],
    ComposedPlatform::class => [
        'getName', 'getFlavor', 'getServerVersion', 'getCapabilitySet',
        'getDefaultCharset', 'getDefaultCollation', 'supportsSchemas', 'ddl',
        'introspection', 'queryDialect', 'quoting', 'types', 'roles',
    ],
    MetadataInspectorSet::class => [
        'server', 'database', 'table', 'column', 'index', 'foreignKeys', 'view',
        'routine', 'trigger', 'sequence', 'checkConstraint', 'user', 'privileges',
        'withServer', 'withForeignKeys', 'withPrivileges',
    ],
    MetadataInspectorSetFactoryInterface::class => ['create'],
    CredentialProviderInterface::class => ['resolve'],
    ConnectionInitializerInterface::class => ['initialize'],
    QueryInterceptorInterface::class => ['intercept'],
    QueryRequest::class => [],
    QueryKind::class => [],
    FormatWriterInterface::class => [
        'getFormatName', 'writeHeader', 'writeTableHeader', 'writeTableDdl',
        'writeRows', 'writeTableFooter', 'writeFooter',
    ],
    FormatReaderInterface::class => ['getFormatName', 'readRows'],
    SinkInterface::class => [],
    ImportSourceInterface::class => [],
    QueryHistoryInterface::class => ['record'],
    MetadataCacheInterface::class => [
        'remember', 'invalidateTable', 'invalidateDatabase', 'clear',
    ],
    ProcessManagerFactoryInterface::class => ['create'],
    ProcessManagerInterface::class => [
        'list', 'kill',
    ],
];
