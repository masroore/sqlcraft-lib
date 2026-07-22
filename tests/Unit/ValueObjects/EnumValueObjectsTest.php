<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\ForeignKeyAction;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\RoutineDirection;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

final class EnumValueObjectsTest extends TestCase
{
    public function test_foreign_key_actions_expose_sql_values(): void
    {
        self::assertSame('SET NULL', ForeignKeyAction::SET_NULL->value);
        self::assertSame('NO ACTION', ForeignKeyAction::NO_ACTION->value);
        self::assertSame('SET DEFAULT', ForeignKeyAction::SET_DEFAULT->value);
    }

    public function test_trigger_enums_expose_all_planned_cases(): void
    {
        self::assertSame(['BEFORE', 'AFTER', 'INSTEAD OF'], array_map(
            static fn (TriggerTiming $timing): string => $timing->value,
            TriggerTiming::cases(),
        ));
        self::assertSame(['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'], array_map(
            static fn (TriggerEvent $event): string => $event->value,
            TriggerEvent::cases(),
        ));
    }

    public function test_routine_and_index_enums_expose_all_planned_cases(): void
    {
        self::assertSame(['IN', 'OUT', 'INOUT'], array_map(
            static fn (RoutineDirection $direction): string => $direction->value,
            RoutineDirection::cases(),
        ));
        self::assertSame(8, count(IndexType::cases()));
        self::assertSame('BRIN', IndexType::BRIN->value);
    }
}
