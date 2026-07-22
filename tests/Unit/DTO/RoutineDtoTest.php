<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\DTO\RoutineParameter;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\RoutineDirection;

final class RoutineDtoTest extends TestCase
{
    public function test_routine_meta_stores_function_details_and_typed_parameters(): void
    {
        $parameter = new RoutineParameter(
            name: 'user_id',
            dataType: new DataType('INT'),
            direction: RoutineDirection::IN,
        );
        $routine = new RoutineMeta(
            name: 'find_user',
            type: 'FUNCTION',
            params: [$parameter],
            returnType: new DataType('VARCHAR', length: 255),
            body: 'RETURN SELECT name FROM users WHERE id = user_id',
            language: 'SQL',
            comment: 'Find one user',
            definer: 'app@localhost',
            deterministic: true,
            sqlDataAccess: 'READS SQL DATA',
        );

        self::assertSame('find_user', $routine->name);
        self::assertSame('FUNCTION', $routine->type);
        self::assertSame($parameter, $routine->params[0]);
        self::assertSame('user_id', $routine->params[0]->name);
        self::assertSame(RoutineDirection::IN, $routine->params[0]->direction);
        $returnType = $routine->returnType;
        self::assertNotNull($returnType);
        self::assertSame('VARCHAR', $returnType->name);
        self::assertSame(255, $returnType->length);
        self::assertSame('SQL', $routine->language);
        self::assertSame('Find one user', $routine->comment);
        self::assertTrue($routine->deterministic);
        self::assertSame('READS SQL DATA', $routine->sqlDataAccess);
    }

    public function test_routine_meta_supports_procedures_without_return_types(): void
    {
        $routine = new RoutineMeta(
            name: 'reset_user',
            type: 'PROCEDURE',
            params: [
                new RoutineParameter(
                    name: 'user_id',
                    dataType: new DataType('INT'),
                    direction: RoutineDirection::INOUT,
                ),
            ],
            returnType: null,
            body: 'UPDATE users SET active = 0 WHERE id = user_id',
            language: 'SQL',
            comment: null,
            definer: 'app@localhost',
            deterministic: false,
            sqlDataAccess: 'MODIFIES SQL DATA',
        );

        self::assertSame('PROCEDURE', $routine->type);
        self::assertNull($routine->returnType);
        self::assertSame(RoutineDirection::INOUT, $routine->params[0]->direction);
        self::assertFalse($routine->deterministic);
        self::assertNull($routine->comment);
    }
}
