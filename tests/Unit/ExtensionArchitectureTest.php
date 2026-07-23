<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Connection\ArrayCredentialProvider;
use SQLCraft\Connection\CallbackCredentialProvider;
use SQLCraft\Connection\CredentialProviderChain;
use SQLCraft\Connection\EnvCredentialProvider;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\Contracts\Import\FormatReaderInterface;
use SQLCraft\Contracts\Import\FormatReadOptions;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\QueryDialectInterface;
use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\Contracts\Platform\TypeMapperInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Enums\QueryKind;
use SQLCraft\Events\ConnectionOpenedEvent;
use SQLCraft\Exceptions\ExtensionConfigurationException;
use SQLCraft\Exceptions\RegistrationNotFoundException;
use SQLCraft\Execution\QueryInterceptorPipeline;
use SQLCraft\Execution\QueryRequest;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\FormatRegistry;
use SQLCraft\Metadata\MetadataInspectorSet;
use SQLCraft\SQLCraftBuilder;
use SQLCraft\Platform\ComposedPlatform;
use SQLCraft\Platform\PlatformRoles;
use SQLCraft\ValueObjects\Credential;
use SQLCraft\ValueObjects\ServerVersion;

final class ExtensionArchitectureTest extends TestCase
{
    public function test_credential_chain_selects_first_non_null_and_short_circuits(): void
    {
        $calls = 0;
        $chain = new CredentialProviderChain([
            new ArrayCredentialProvider(['app' => new Credential('alice', 'secret')]),
            new CallbackCredentialProvider(static function (string $key) use (&$calls): Credential {
                $calls++;

                return new Credential('fallback');
            }),
        ]);

        self::assertSame('alice', $chain->resolve('app')?->username);
        self::assertSame(0, $calls);
        self::assertNull((new CredentialProviderChain([new ArrayCredentialProvider([])]))->resolve('missing'));
    }

    public function test_credential_chain_propagates_provider_errors_and_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CredentialProviderChain([]);
    }

    public function test_environment_provider_returns_partial_credentials_and_null_misses(): void
    {
        putenv('EXT_TEST_APP_USERNAME=alice');
        putenv('EXT_TEST_APP_PASSWORD');
        try {
            $credential = (new EnvCredentialProvider('EXT_TEST_'))->resolve('app');
            self::assertInstanceOf(Credential::class, $credential);
            self::assertSame('alice', $credential->username);
            self::assertNull($credential->password);
            self::assertNull((new EnvCredentialProvider('EXT_TEST_'))->resolve('missing'));
        } finally {
            putenv('EXT_TEST_APP_USERNAME');
            putenv('EXT_TEST_APP_PASSWORD');
        }
    }

    public function test_format_registry_creates_fresh_factory_adapters_and_validates_names(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $registry = new FormatRegistry($connection);
        $seen = [];
        $registry->registerWriterFactory('custom', static function (ConnectionInterface $active) use (&$seen): FormatWriterInterface {
            $seen[] = $active;

            return new ExtensionTestWriter('custom');
        });
        $registry->registerReaderFactory('custom', static fn (): FormatReaderInterface => new ExtensionTestReader('custom'));

        self::assertNotSame($registry->getWriter('custom'), $registry->getWriter('custom'));
        self::assertSame([$connection, $connection], $seen);
        self::assertNotSame($registry->getReader('custom'), $registry->getReader('custom'));
        self::assertSame(['custom'], $registry->getSupportedWriteFormats());
        self::assertSame(['custom'], $registry->getSupportedReadFormats());
    }

    public function test_format_registry_replacement_and_missing_registration_rules(): void
    {
        $registry = new FormatRegistry(self::createStub(ConnectionInterface::class));
        $registry->registerWriterFactory('custom', static fn (ConnectionInterface $connection): FormatWriterInterface => new ExtensionTestWriter('custom', 'first'));
        $registry->replaceWriterFactory('custom', static fn (ConnectionInterface $connection): FormatWriterInterface => new ExtensionTestWriter('custom', 'second'));
        self::assertInstanceOf(ExtensionTestWriter::class, $registry->getWriter('custom'));
        $writer = $registry->getWriter('custom');
        self::assertInstanceOf(ExtensionTestWriter::class, $writer);
        self::assertSame('second', $writer->marker);

        $this->expectException(RegistrationNotFoundException::class);
        $registry->replaceReaderFactory('missing', static fn (): FormatReaderInterface => new ExtensionTestReader('missing'));
    }

    public function test_format_registry_rejects_wrong_adapter_name_and_missing_connection(): void
    {
        $registry = new FormatRegistry(self::createStub(ConnectionInterface::class));
        $registry->registerWriterFactory('custom', static fn (ConnectionInterface $connection): FormatWriterInterface => new ExtensionTestWriter('wrong'));
        $this->expectException(ExtensionConfigurationException::class);
        $registry->getWriter('custom');
    }

    public function test_query_interceptors_transform_in_order_and_preserve_provenance(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $request = (new QueryInterceptorPipeline([
            new ExtensionAppendInterceptor(' /* a */'),
            new ExtensionAppendInterceptor(' /* b */'),
        ]))->process($connection, 'SELECT 1', ['id' => 1], QueryKind::Select);

        self::assertSame($connection, $request->connection);
        self::assertSame('SELECT 1', $request->originalSql);
        self::assertSame('SELECT 1 /* a */ /* b */', $request->sql);
        self::assertSame(['id' => 1], $request->params);
    }

    public function test_query_interceptors_reject_empty_and_mixed_parameters(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $this->expectException(ExtensionConfigurationException::class);
        (new QueryInterceptorPipeline([new ExtensionEmptyInterceptor()]))->process($connection, 'SELECT 1', [], QueryKind::Select);
    }

    public function test_query_request_is_immutable_and_timeout_provenance_is_supported(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $request = new QueryRequest($connection, 'SELECT 1', 'SELECT 1 /* wrapped */', [], QueryKind::Select);
        $changed = $request->withSqlAndParams('SELECT 2', ['x' => 2]);

        self::assertSame('SELECT 1', $request->originalSql);
        self::assertSame('SELECT 1 /* wrapped */', $request->sql);
        self::assertSame('SELECT 2', $changed->sql);
        self::assertSame(['x' => 2], $changed->params);
    }

    public function test_platform_roles_and_composed_platform_preserve_role_identity(): void
    {
        $roles = new PlatformRoles(
            $ddl = self::createStub(DdlDialectInterface::class),
            $introspection = self::createStub(IntrospectionDialectInterface::class),
            $query = self::createStub(QueryDialectInterface::class),
            $quoting = self::createStub(QuotingInterface::class),
            $types = self::createStub(TypeMapperInterface::class),
        );
        $replacement = self::createStub(QueryDialectInterface::class);
        $changed = $roles->withQueryDialect($replacement);
        self::assertSame($replacement, $changed->queryDialect);
        self::assertSame($ddl, $changed->ddl);
        self::assertSame($introspection, $changed->introspection);
        self::assertSame($quoting, $changed->quoting);
        self::assertSame($types, $changed->types);

        $version = new ServerVersion('1.2.3');
        $capabilities = new CapabilitySet([]);
        $platform = new ComposedPlatform('fixture', $changed, static fn (ConnectionInterface $connection): ServerVersion => $version, static fn (ServerVersion $version): CapabilitySet => $capabilities, flavor: 'test', supportsSchemas: true);
        self::assertSame('fixture', $platform->getName());
        self::assertSame($version, $platform->getServerVersion(self::createStub(ConnectionInterface::class)));
        self::assertSame($capabilities, $platform->getCapabilitySet($version));
        self::assertTrue($platform->supportsSchemas());
        self::assertSame($replacement, $platform->queryDialect());
    }

    public function test_metadata_inspector_set_getters_and_withers_are_role_local(): void
    {
        [$set, $server, $database, $table, $column, $index, $foreignKeys, $view, $routine, $trigger, $sequence, $check, $user] = $this->metadataSet();
        self::assertSame($server, $set->server());
        self::assertSame($database, $set->database());
        self::assertSame($table, $set->table());
        self::assertSame($column, $set->column());
        self::assertSame($index, $set->index());
        self::assertSame($foreignKeys, $set->foreignKeys());
        self::assertSame($view, $set->view());
        self::assertSame($routine, $set->routine());
        self::assertSame($trigger, $set->trigger());
        self::assertSame($sequence, $set->sequence());
        self::assertSame($check, $set->checkConstraint());
        self::assertSame($user, $set->user());
        self::assertNull($set->privileges());
        $newServer = self::createStub(\SQLCraft\Contracts\Metadata\ServerInspectorInterface::class);
        self::assertSame($newServer, $set->withServer($newServer)->server());
        self::assertSame($set->foreignKeys(), $set->withServer($newServer)->foreignKeys());
    }

    public function test_builder_listener_modes_and_defaults_are_live(): void
    {
        $calls = [];
        $factory = SQLCraftBuilder::defaults()
            ->listen(ConnectionOpenedEvent::class, static function () use (&$calls): void {
                $calls[] = 'default';
            })
            ->listen(ConnectionOpenedEvent::class, static function () use (&$calls): void {
                $calls[] = 'priority';
            }, 1)
            ->build();
        $session = $factory->session(new \SQLCraft\ValueObjects\ConnectionParameters(database: ':memory:', driver: 'sqlite'));
        self::assertSame(['priority', 'default'], $calls);
        $session->connection()->close();

        $builder = (new SQLCraftBuilder())->eventDispatcher(self::createStub(EventDispatcherInterface::class));
        $this->expectException(ExtensionConfigurationException::class);
        $builder->listen(ConnectionOpenedEvent::class, static function (): void {
        });
    }

    /**
     * @return array{
     *     0: MetadataInspectorSet,
     *     1: \SQLCraft\Contracts\Metadata\ServerInspectorInterface,
     *     2: \SQLCraft\Contracts\Metadata\DatabaseInspectorInterface,
     *     3: \SQLCraft\Contracts\Metadata\TableInspectorInterface,
     *     4: \SQLCraft\Contracts\Metadata\ColumnInspectorInterface,
     *     5: \SQLCraft\Contracts\Metadata\IndexInspectorInterface,
     *     6: \SQLCraft\Contracts\Metadata\ForeignKeyInspectorInterface,
     *     7: \SQLCraft\Contracts\Metadata\ViewInspectorInterface,
     *     8: \SQLCraft\Contracts\Metadata\RoutineInspectorInterface,
     *     9: \SQLCraft\Contracts\Metadata\TriggerInspectorInterface,
     *     10: \SQLCraft\Contracts\Metadata\SequenceInspectorInterface,
     *     11: \SQLCraft\Contracts\Metadata\CheckConstraintInspectorInterface,
     *     12: \SQLCraft\Contracts\Metadata\UserInspectorInterface,
     * }
     */
    private function metadataSet(): array
    {
        $server = self::createStub(\SQLCraft\Contracts\Metadata\ServerInspectorInterface::class);
        $database = self::createStub(\SQLCraft\Contracts\Metadata\DatabaseInspectorInterface::class);
        $table = self::createStub(\SQLCraft\Contracts\Metadata\TableInspectorInterface::class);
        $column = self::createStub(\SQLCraft\Contracts\Metadata\ColumnInspectorInterface::class);
        $index = self::createStub(\SQLCraft\Contracts\Metadata\IndexInspectorInterface::class);
        $foreignKeys = self::createStub(\SQLCraft\Contracts\Metadata\ForeignKeyInspectorInterface::class);
        $view = self::createStub(\SQLCraft\Contracts\Metadata\ViewInspectorInterface::class);
        $routine = self::createStub(\SQLCraft\Contracts\Metadata\RoutineInspectorInterface::class);
        $trigger = self::createStub(\SQLCraft\Contracts\Metadata\TriggerInspectorInterface::class);
        $sequence = self::createStub(\SQLCraft\Contracts\Metadata\SequenceInspectorInterface::class);
        $check = self::createStub(\SQLCraft\Contracts\Metadata\CheckConstraintInspectorInterface::class);
        $user = self::createStub(\SQLCraft\Contracts\Metadata\UserInspectorInterface::class);

        return [
            new MetadataInspectorSet($server, $database, $table, $column, $index, $foreignKeys, $view, $routine, $trigger, $sequence, $check, $user),
            $server,
            $database,
            $table,
            $column,
            $index,
            $foreignKeys,
            $view,
            $routine,
            $trigger,
            $sequence,
            $check,
            $user,
        ];
    }
}

final class ExtensionAppendInterceptor implements \SQLCraft\Contracts\Execution\QueryInterceptorInterface
{
    public function __construct(private readonly string $suffix)
    {
    }

    #[\Override]
    public function intercept(QueryRequest $request): QueryRequest
    {
        return $request->withSqlAndParams($request->sql . $this->suffix, $request->params);
    }
}

final class ExtensionEmptyInterceptor implements \SQLCraft\Contracts\Execution\QueryInterceptorInterface
{
    #[\Override]
    public function intercept(QueryRequest $request): QueryRequest
    {
        return $request->withSqlAndParams(' ', $request->params);
    }
}

final class ExtensionTestWriter implements FormatWriterInterface
{
    public function __construct(private readonly string $name, public readonly string $marker = '')
    {
    }

    #[\Override]
    public function getFormatName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
    }

    #[\Override]
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
    }

    /** @param list<string> $ddlStatements */
    #[\Override]
    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void
    {
    }

    /** @param list<array<string, mixed>> $rows @param list<ColumnMeta> $columns */
    #[\Override]
    public function writeRows(SinkInterface $sink, TableStatus $table, array $rows, array $columns, DumpOptions $options): void
    {
    }

    #[\Override]
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
    }

    #[\Override]
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
    }
}

final class ExtensionTestReader implements FormatReaderInterface
{
    public function __construct(private readonly string $name)
    {
    }

    #[\Override]
    public function getFormatName(): string
    {
        return $this->name;
    }

    /** @return \Generator<int, array<string, mixed>> */
    #[\Override]
    public function readRows(mixed $stream, FormatReadOptions $options): \Generator
    {
        yield from [];
    }
}
