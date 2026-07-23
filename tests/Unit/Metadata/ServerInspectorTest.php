<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\Metadata\ServerInspector;
use SQLCraft\ValueObjects\ServerVersion;

final class ServerInspectorTest extends TestCase
{
    public function test_it_hydrates_server_collections_and_key_value_maps(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $platform->method('getName')->willReturn('mysql');
        $platform->method('getFlavor')->willReturn(null);
        $platform->method('getDefaultCharset')->willReturn('utf8mb4');
        $platform->method('getDefaultCollation')->willReturn('utf8mb4_general_ci');
        $introspection->method('getDatabasesSql')->willReturn('databases');
        $introspection->method('getVariablesSql')->willReturn('variables');
        $introspection->method('getStatusSql')->willReturn('status');
        $introspection->method('getProcesslistSql')->willReturn('processes');
        $introspection->method('getCharsetsSql')->willReturn('charsets');
        $introspection->method('getCollationsSql')->with('utf8mb4')->willReturn('collations');

        $results = [];
        foreach ([
            'databases' => [['name' => 'shop']],
            'variables' => [['variable_name' => 'version', 'value' => '8.4']],
            'status' => [['name' => 'Threads_connected', 'value' => 2]],
            'processes' => [['Id' => 7, 'User' => 'app', 'Command' => 'Query', 'Time' => 3]],
            'charsets' => [['Charset' => 'utf8mb4']],
            'collations' => [['Collation' => 'utf8mb4_general_ci']],
        ] as $sql => $rows) {
            $result = self::createMock(ResultInterface::class);
            $result->method('fetchAll')->willReturn($rows);
            $results[$sql] = $result;
        }

        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->method('getServerVersion')->willReturn(new ServerVersion('8.4.0'));
        $connection->expects(self::exactly(6))->method('query')->willReturnCallback(
            static function (string $sql) use ($results): ResultInterface {
                return $results[$sql] ?? throw new \LogicException('Unexpected server SQL.');
            },
        );

        $inspector = new ServerInspector(new MySQLMetadataFactory);
        $info = $inspector->getServerInfo($connection);
        $databases = $inspector->getDatabases($connection);
        $variables = $inspector->getVariables($connection);
        $status = $inspector->getStatus($connection);
        $processes = $inspector->getProcessList($connection);
        $charsets = $inspector->getCharsets($connection);
        $collations = $inspector->getCollations($connection, 'utf8mb4');

        self::assertSame('mysql', $info->platformName);
        self::assertSame('shop', $databases->get('shop')->name);
        self::assertSame('8.4', $variables['version']);
        self::assertSame('2', $status['Threads_connected']);
        self::assertSame('app', $processes->get(7)->user);
        self::assertSame('utf8mb4', $charsets->get('utf8mb4')->name);
        self::assertSame('utf8mb4_general_ci', $collations->get('utf8mb4_general_ci')->name);
    }

    public function test_unsupported_charset_inspection_remains_capability_gated(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $introspection->method('getCharsetsSql')->willThrowException(
            CapabilityNotSupportedException::for(Capability::Charset, 'sqlite'),
        );
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);

        $this->expectException(CapabilityNotSupportedException::class);

        (new ServerInspector(new MySQLMetadataFactory))->getCharsets($connection);
    }
}
