<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\Metadata\ServerInspector;

final class ServerInspectorTest extends TestCase
{
    public function testItHydratesServerCollectionsAndKeyValueMaps(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getName')->willReturn('mysql');
        $platform->method('getFlavor')->willReturn(null);
        $platform->method('getDefaultCharset')->willReturn('utf8mb4');
        $platform->method('getDefaultCollation')->willReturn('utf8mb4_general_ci');
        $platform->method('getDatabasesSql')->willReturn('databases');
        $platform->method('getVariablesSql')->willReturn('variables');
        $platform->method('getStatusSql')->willReturn('status');
        $platform->method('getProcesslistSql')->willReturn('processes');
        $platform->method('getCharsetsSql')->willReturn('charsets');
        $platform->method('getCollationsSql')->with('utf8mb4')->willReturn('collations');

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
        $connection->method('getServerVersion')->willReturn(new \SQLCraft\ValueObjects\ServerVersion('8.4.0'));
        $connection->expects(self::exactly(6))->method('query')->willReturnCallback(
            static function (string $sql) use ($results): ResultInterface {
                return $results[$sql] ?? throw new \LogicException('Unexpected server SQL.');
            },
        );

        $inspector = new ServerInspector(new MySQLMetadataFactory());
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

    public function testUnsupportedCharsetInspectionRemainsCapabilityGated(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getCharsetsSql')->willThrowException(
            CapabilityNotSupportedException::for(\SQLCraft\Capabilities\Capability::Charset, 'sqlite'),
        );
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);

        $this->expectException(CapabilityNotSupportedException::class);

        (new ServerInspector(new MySQLMetadataFactory()))->getCharsets($connection);
    }
}
