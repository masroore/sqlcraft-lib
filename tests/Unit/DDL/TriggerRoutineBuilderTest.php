<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\DDL\CreateRoutineBuilder;
use SQLCraft\DDL\CreateTriggerBuilder;
use SQLCraft\DDL\Definition\RoutineParameterDefinition;
use SQLCraft\DDL\DropRoutineBuilder;
use SQLCraft\DDL\DropTriggerBuilder;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\RoutineDirection;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

final class TriggerRoutineBuilderTest extends TestCase
{
    public function test_trigger_and_routine_builders_delegate_typed_intent(): void
    {
        $name = new QualifiedName(new Identifier('audit_trigger'));
        $table = new QualifiedName(new Identifier('users'));
        $routine = new QualifiedName(new Identifier('refresh_users'));
        $parameter = new RoutineParameterDefinition('limit', new DataType('INTEGER'), RoutineDirection::IN);
        $dialect = self::createMock(DdlDialectInterface::class);
        $dialect->expects(self::once())->method('renderCreateTriggerStatement')->with($name, $table, TriggerTiming::AFTER, TriggerEvent::INSERT, 'BEGIN END', null, 'ROW')->willReturn('CREATE TRIGGER');
        $dialect->expects(self::once())->method('renderDropTriggerStatement')->with($name, null, true)->willReturn('DROP TRIGGER');
        $dialect->expects(self::once())->method('renderCreateRoutineStatement')->with($routine, 'FUNCTION', [$parameter], self::isNull(), 'RETURN 1', 'SQL', true, true)->willReturn('CREATE FUNCTION');
        $dialect->expects(self::once())->method('renderDropRoutineStatement')->with($routine, 'FUNCTION', true)->willReturn('DROP FUNCTION');

        self::assertSame(['CREATE TRIGGER'], (new CreateTriggerBuilder($name, $table, TriggerTiming::AFTER, TriggerEvent::INSERT, 'BEGIN END'))->toSql($dialect));
        self::assertSame(['DROP TRIGGER'], (new DropTriggerBuilder($name, null, true))->toSql($dialect));
        self::assertSame(['CREATE FUNCTION'], (new CreateRoutineBuilder($routine, 'FUNCTION', [$parameter], null, 'RETURN 1', 'SQL', true, true))->toSql($dialect));
        self::assertSame(['DROP FUNCTION'], (new DropRoutineBuilder($routine, 'FUNCTION', true))->toSql($dialect));
    }

    public function test_sqlite_rejects_routine_rendering(): void
    {
        $this->expectException(CapabilityNotSupportedException::class);

        (new CreateRoutineBuilder(new QualifiedName(new Identifier('refresh_users')), 'FUNCTION'))->toSql(new SqlitePlatform);
    }
}
