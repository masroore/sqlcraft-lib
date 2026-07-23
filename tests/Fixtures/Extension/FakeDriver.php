<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Fixtures\Extension;

use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Connection\Transaction;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PreparedStatementInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\Contracts\Metadata\MetadataInspectorSetFactoryInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Metadata\DefaultMetadataInspectorSetFactory;
use SQLCraft\Metadata\MetadataInspectorSet;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\Platform\ComposedPlatform;
use SQLCraft\Platform\PlatformRoles;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\ServerVersion;

final class FakePlatformRoles
{
    public static function create(): ComposedPlatform
    {
        $sqlite = new SqlitePlatform;

        return new ComposedPlatform(
            name: 'fixturedb',
            roles: new PlatformRoles(
                $sqlite->ddl(),
                $sqlite->introspection(),
                $sqlite->queryDialect(),
                $sqlite->quoting(),
                $sqlite->types(),
            ),
            serverVersion: static fn (ConnectionInterface $connection): ServerVersion => $sqlite->getServerVersion($connection),
            capabilities: static fn (ServerVersion $version): CapabilitySet => $sqlite->getCapabilitySet($version),
            supportsSchemas: false,
        );
    }
}

final class FakeMetadataInspectorSetFactory implements MetadataInspectorSetFactoryInterface
{
    public int $created = 0;

    private readonly DefaultMetadataInspectorSetFactory $delegate;

    public function __construct()
    {
        $this->delegate = new DefaultMetadataInspectorSetFactory(new SqliteMetadataFactory);
    }

    #[\Override]
    public function create(ConnectionInterface $connection): MetadataInspectorSet
    {
        $this->created++;

        return $this->delegate->create($connection);
    }
}

final class FakeDriver implements DriverInterface
{
    private readonly SqliteDriver $sqlite;

    private readonly PlatformInterface $platform;

    public function __construct(public readonly ConnectionEventDispatcherInterface $events)
    {
        $this->sqlite = new SqliteDriver(
            new PdoConnectionFactory(new PdoExceptionTranslator, emitLifecycleEvents: false),
            new SqlitePlatform,
        );
        $this->platform = FakePlatformRoles::create();
    }

    #[\Override]
    public function buildDsn(ConnectionParameters $params): string
    {
        return $this->sqlite->buildDsn($params);
    }

    #[\Override]
    public function connect(ConnectionParameters $params, ?string $name = null): ConnectionInterface
    {
        return new FakeConnection($this->sqlite->connect($params, $name), $this->platform);
    }

    #[\Override]
    public function getPlatform(ConnectionInterface $connection): PlatformInterface
    {
        return $connection->getPlatform();
    }

    #[\Override]
    public function getName(): string
    {
        return 'fixturedb';
    }

    /** @return list<string> */
    #[\Override]
    public function getPdoDriverNames(): array
    {
        return ['sqlite'];
    }
}

/** @internal Test-only adapter that changes the platform identity without changing SQLite I/O. */
final class FakeConnection implements ConnectionInterface
{
    public function __construct(
        private readonly ConnectionInterface $delegate,
        private readonly PlatformInterface $platform,
    ) {}

    #[\Override]
    public function getPlatformName(): string
    {
        return $this->platform->getName();
    }

    #[\Override]
    public function getServerVersion(): ServerVersion
    {
        return $this->platform->getServerVersion($this);
    }

    #[\Override]
    public function getPlatform(): PlatformInterface
    {
        return $this->platform;
    }

    #[\Override]
    public function getName(): ?string
    {
        return $this->delegate->getName();
    }

    #[\Override]
    public function getDatabaseName(): ?string
    {
        return $this->delegate->getDatabaseName();
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function execute(string $sql, array $params = []): ExecutionResult
    {
        return $this->delegate->execute($sql, $params);
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function query(string $sql, array $params = [], bool $streaming = false): ResultInterface
    {
        return $this->delegate->query($sql, $params, $streaming);
    }

    #[\Override]
    public function prepare(string $sql): PreparedStatementInterface
    {
        return $this->delegate->prepare($sql);
    }

    #[\Override]
    public function quoteIdentifier(string $name): string
    {
        return $this->delegate->quoteIdentifier($name);
    }

    #[\Override]
    public function quoteValue(mixed $value): string
    {
        return $this->delegate->quoteValue($value);
    }

    #[\Override]
    public function lastInsertId(?string $sequenceName = null): string|int|false
    {
        return $this->delegate->lastInsertId($sequenceName);
    }

    #[\Override]
    public function affectedRows(): int
    {
        return $this->delegate->affectedRows();
    }

    #[\Override]
    public function beginTransaction(string $isolationLevel = ''): Transaction
    {
        return $this->delegate->beginTransaction($isolationLevel);
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->delegate->inTransaction();
    }

    #[\Override]
    public function ping(): bool
    {
        return $this->delegate->ping();
    }

    #[\Override]
    public function isConnected(): bool
    {
        return $this->delegate->isConnected();
    }

    #[\Override]
    public function close(): void
    {
        $this->delegate->close();
    }
}
