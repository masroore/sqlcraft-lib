<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Contract;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\Identifier;

abstract class PlatformConformanceTestCase extends TestCase
{
    private ConnectionInterface $connection;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->connection->execute('DROP TABLE IF EXISTS contract_fixture_rows');
        $this->connection->execute('CREATE TABLE contract_fixture_rows (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        for ($id = 1; $id <= 10; $id++) {
            $this->connection->execute('INSERT INTO contract_fixture_rows (id, value) VALUES (?, ?)', [$id, 'row-' . $id]);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function test_quoted_identifier_is_accepted_by_the_live_engine(): void
    {
        $identifier = new Identifier('weird`alias');
        $row = $this->connection->query('SELECT 1 AS ' . $this->platform()->quoteIdentifier($identifier))->fetchAssoc();

        self::assertSame(1, $row['weird`alias'] ?? null);
    }

    public function test_pagination_never_exceeds_the_requested_limit(): void
    {
        $sql = $this->platform()->applyPagination(
            'SELECT id FROM contract_fixture_rows ORDER BY id',
            limit: 5,
            offset: 0,
        );

        self::assertCount(5, $this->connection->query($sql)->fetchAll());
    }

    public function test_offset_pagination_starts_at_the_requested_row(): void
    {
        $sql = $this->platform()->applyPagination(
            'SELECT id FROM contract_fixture_rows ORDER BY id',
            limit: 3,
            offset: 4,
        );

        self::assertSame(
            [['id' => 5], ['id' => 6], ['id' => 7]],
            $this->connection->query($sql)->fetchAll(),
        );
    }

    public function test_quoted_string_is_accepted_as_a_value(): void
    {
        $row = $this->connection->query(
            'SELECT ' . $this->platform()->quoteValue("O'Reilly") . ' AS value',
        )->fetchAssoc();

        self::assertSame("O'Reilly", $row['value'] ?? null);
    }

    public function test_live_server_exposes_the_declared_platform_capabilities(): void
    {
        $platform = $this->platform();
        $capabilities = $platform->getCapabilitySet($this->connection->getServerVersion());

        self::assertTrue($capabilities->has(Capability::Table));
        self::assertTrue($capabilities->has(Capability::Columns));
        self::assertTrue($capabilities->has(Capability::Sql));
        self::assertSame($platform->getName(), $this->connection->getPlatformName());
    }

    abstract protected function createConnection(): ConnectionInterface;

    abstract protected function platform(): PlatformInterface;
}
