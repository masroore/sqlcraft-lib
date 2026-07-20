<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\PostgreSQLMetadataFactory;
use SQLCraft\Metadata\SequenceInspector;

final class SequenceInspectorTest extends TestCase
{
    public function testItHydratesSequencesThroughTheDialect(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->expects(self::once())->method('getSequencesSql')->with('public')->willReturn('sequences');
        $result = self::createMock(ResultInterface::class);
        $result->method('fetchAll')->willReturn([[
            'sequence_name' => 'users_id_seq',
            'sequence_schema' => 'public',
            'start_value' => '1',
            'minimum_value' => '1',
            'maximum_value' => '99',
            'increment' => 1,
            'cycle' => true,
        ]]);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::once())->method('query')->with('sequences')->willReturn($result);

        $sequences = (new SequenceInspector(new PostgreSQLMetadataFactory()))->getSequences($connection, 'public');

        self::assertTrue($sequences->get('users_id_seq')->cycle);
    }
}
