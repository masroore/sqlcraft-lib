<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\DatabaseInspector;
use SQLCraft\Metadata\PostgreSQLMetadataFactory;

final class DatabaseInspectorTest extends TestCase
{
    public function testItHydratesSchemasSequencesAndTypes(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->expects(self::once())->method('getSchemasSql')->willReturn('schemas');
        $platform->expects(self::once())->method('getSequencesSql')->with('public')->willReturn('sequences');
        $platform->expects(self::once())->method('getTypesSql')->with('public')->willReturn('types');

        $schemaResult = self::createMock(ResultInterface::class);
        $schemaResult->method('fetchAll')->willReturn([[
            'schema_name' => 'public',
            'catalog_name' => 'shop',
            'schema_owner' => 'owner',
        ]]);
        $sequenceResult = self::createMock(ResultInterface::class);
        $sequenceResult->method('fetchAll')->willReturn([[
            'sequence_name' => 'users_id_seq',
            'sequence_schema' => 'public',
            'start_value' => '1',
            'minimum_value' => '1',
            'maximum_value' => '9223372036854775807',
            'increment' => '1',
            'cycle' => false,
        ]]);
        $typeResult = self::createMock(ResultInterface::class);
        $typeResult->method('fetchAll')->willReturn([['data_type' => 'custom_enum']]);

        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::exactly(3))->method('query')->willReturnCallback(
            static function (string $sql) use ($schemaResult, $sequenceResult, $typeResult): ResultInterface {
                return match ($sql) {
                    'schemas' => $schemaResult,
                    'sequences' => $sequenceResult,
                    'types' => $typeResult,
                    default => throw new \LogicException('Unexpected metadata SQL.'),
                };
            },
        );

        $inspector = new DatabaseInspector(new PostgreSQLMetadataFactory());
        $schemas = $inspector->getSchemas($connection);
        $sequences = $inspector->getSequences($connection, 'public');
        $types = $inspector->getTypes($connection, 'public');

        self::assertSame('owner', $schemas->get('public')->owner);
        self::assertSame(1, $sequences->get('users_id_seq')->increment);
        self::assertSame('CUSTOM_ENUM', $types->get('CUSTOM_ENUM')->name);
    }

    public function testUnsupportedTypesRemainCapabilityErrors(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getTypesSql')->willThrowException(
            CapabilityNotSupportedException::for(\SQLCraft\Capabilities\Capability::Type, 'sqlite'),
        );
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);

        $this->expectException(CapabilityNotSupportedException::class);

        (new DatabaseInspector(new PostgreSQLMetadataFactory()))->getTypes($connection);
    }

    public function testSchemaListingCanBeEmptyWhenPlatformHasNoSchemaConcept(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getSchemasSql')->willReturn('');
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);

        $schemas = (new DatabaseInspector(new PostgreSQLMetadataFactory()))->getSchemas($connection);

        self::assertCount(0, $schemas);
    }
}
