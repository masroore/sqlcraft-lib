<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\CheckConstraintInspector;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\Metadata\RoutineInspector;
use SQLCraft\Metadata\UserInspector;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class RoutineConstraintUserInspectorTest extends TestCase
{
    public function test_it_filters_routines_and_hydrates_constraints_and_users(): void
    {
        $routine = new QualifiedName(new Identifier('refresh_users'));
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getRoutinesSql')->with('app')->willReturn('routines');
        $platform->method('getRoutineDetailSql')->with($routine)->willReturn('routine');
        $platform->method('getCheckConstraintsSql')->with($routine)->willReturn('checks');
        $platform->method('getUsersSql')->willReturn('users');

        $results = [];
        foreach ([
            'routines' => [
                ['ROUTINE_NAME' => 'refresh_users', 'ROUTINE_TYPE' => 'PROCEDURE', 'ROUTINE_DEFINITION' => 'CALL users_refresh()'],
                ['ROUTINE_NAME' => 'active_users', 'ROUTINE_TYPE' => 'FUNCTION', 'ROUTINE_DEFINITION' => 'RETURN 1'],
            ],
            'routine' => [['ROUTINE_NAME' => 'refresh_users', 'ROUTINE_TYPE' => 'PROCEDURE', 'ROUTINE_DEFINITION' => 'CALL users_refresh()']],
            'checks' => [['CONSTRAINT_NAME' => 'users_age_check', 'CHECK_CLAUSE' => 'age > 0']],
            'users' => [['User' => 'app', 'Host' => '%', 'Super_priv' => 'N', 'account_locked' => 'N']],
        ] as $sql => $rows) {
            $result = self::createMock(ResultInterface::class);
            if ($sql === 'routine') {
                $result->method('fetchAssoc')->willReturn($rows[0]);
            } else {
                $result->method('fetchAll')->willReturn($rows);
            }
            $results[$sql] = $result;
        }

        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::exactly(5))->method('query')->willReturnCallback(
            static function (string $sql) use ($results): ResultInterface {
                return $results[$sql] ?? throw new \LogicException('Unexpected object SQL.');
            },
        );

        $factory = new MySQLMetadataFactory;
        $routines = (new RoutineInspector($factory))->getFunctions($connection, 'app');
        $procedures = (new RoutineInspector($factory))->getProcedures($connection, 'app');
        $detail = (new RoutineInspector($factory))->getRoutineDetail($connection, $routine);
        $checks = (new CheckConstraintInspector($factory))->getCheckConstraints($connection, $routine);
        $users = (new UserInspector($factory))->getUsers($connection);

        self::assertSame('active_users', $routines->get('active_users')->name);
        self::assertSame('refresh_users', $procedures->get('refresh_users')->name);
        self::assertSame('refresh_users', $detail->name);
        self::assertSame('age > 0', $checks->get('users_age_check')->expression);
        self::assertTrue($users->get('app')->canLogin);
    }

    public function test_unsupported_user_inspection_remains_capability_gated(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getUsersSql')->willThrowException(
            CapabilityNotSupportedException::for(Capability::Privileges, 'sqlite'),
        );
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);

        $this->expectException(CapabilityNotSupportedException::class);

        (new UserInspector(new MySQLMetadataFactory))->getUsers($connection);
    }
}
