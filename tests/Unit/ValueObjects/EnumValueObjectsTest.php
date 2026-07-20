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
    public function testForeignKeyActionsExposeSqlValues(): void
    {
        self::assertSame('SET NULL', ForeignKeyAction::SET_NULL->value);
        self::assertSame('NO ACTION', ForeignKeyAction::NO_ACTION->value);
        self::assertSame('SET DEFAULT', ForeignKeyAction::SET_DEFAULT->value);
    }

    public function testTriggerEnumsExposeAllPlannedCases(): void
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

    public function testRoutineAndIndexEnumsExposeAllPlannedCases(): void
    {
        self::assertSame(['IN', 'OUT', 'INOUT'], array_map(
            static fn (RoutineDirection $direction): string => $direction->value,
            RoutineDirection::cases(),
        ));
        self::assertSame(8, count(IndexType::cases()));
        self::assertSame('BRIN', IndexType::BRIN->value);
    }
}
