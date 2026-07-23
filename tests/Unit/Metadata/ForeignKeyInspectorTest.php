<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\ForeignKeyInspector;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\ValueObjects\ForeignKeyAction;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class ForeignKeyInspectorTest extends TestCase
{
    public function test_it_hydrates_outgoing_and_referencing_keys_through_separate_dialect_queries(): void
    {
        $table = new QualifiedName(new Identifier('teams'));
        $outgoingSql = 'outgoing';
        $referencingSql = 'referencing';
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $introspection->expects(self::once())->method('getForeignKeysSql')->with($table)->willReturn($outgoingSql);
        $introspection->expects(self::once())->method('getReferencingForeignKeysSql')->with($table)->willReturn($referencingSql);
        $outgoingResult = self::createMock(ResultInterface::class);
        $outgoingResult->expects(self::once())->method('fetchAll')->willReturn([[
            'constraint_name' => 'users_team_fk',
            'source_column' => 'team_id',
            'target_table' => 'teams',
            'target_column' => 'id',
            'on_delete' => 'CASCADE',
        ]]);
        $referencingResult = self::createMock(ResultInterface::class);
        $referencingResult->expects(self::once())->method('fetchAll')->willReturn([[
            'constraint_name' => 'projects_team_fk',
            'source_column' => 'team_id',
            'target_table' => 'teams',
            'target_column' => 'id',
            'on_delete' => 'SET NULL',
        ]]);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::exactly(2))->method('query')->willReturnCallback(
            static function (string $sql, array $params = [], bool $streaming = false) use ($outgoingSql, $referencingSql, $outgoingResult, $referencingResult): ResultInterface {
                return match ($sql) {
                    $outgoingSql => $outgoingResult,
                    $referencingSql => $referencingResult,
                    default => throw new \LogicException('Unexpected metadata SQL.'),
                };
            },
        );

        $inspector = new ForeignKeyInspector(new SqliteMetadataFactory());
        $outgoing = $inspector->getForeignKeys($connection, $table);
        $referencing = $inspector->getReferencingKeys($connection, $table);

        self::assertSame(ForeignKeyAction::CASCADE, $outgoing->get('users_team_fk')->onDelete);
        self::assertSame(ForeignKeyAction::SET_NULL, $referencing->get('projects_team_fk')->onDelete);
    }
}
