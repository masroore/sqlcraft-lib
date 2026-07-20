<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\BackwardKeyMeta;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\PartitionInfo;
use SQLCraft\DTO\ProcessMeta;
use SQLCraft\DTO\UserMeta;
use SQLCraft\DTO\ViewMeta;

final class RemainingDtoTest extends TestCase
{
    public function testViewMetaStoresViewDefinitionAndMaterializationState(): void
    {
        $view = new ViewMeta(
            name: 'active_users',
            schema: 'public',
            definition: 'SELECT id, name FROM users WHERE active = true',
            materialized: false,
        );

        self::assertSame('active_users', $view->name);
        self::assertSame('public', $view->schema);
        self::assertSame('SELECT id, name FROM users WHERE active = true', $view->definition);
        self::assertFalse($view->materialized);
    }

    public function testViewMetaSupportsMaterializedViewsWithoutLoadedDefinition(): void
    {
        $view = new ViewMeta(
            name: 'daily_totals',
            schema: null,
            definition: null,
            materialized: true,
        );

        self::assertTrue($view->materialized);
        self::assertNull($view->schema);
        self::assertNull($view->definition);
    }

    public function testCheckConstraintMetaStoresRawExpressionAndEnforcement(): void
    {
        $constraint = new CheckConstraintMeta(
            name: 'users_age_check',
            expression: 'age >= 0',
            enforced: true,
        );

        self::assertSame('users_age_check', $constraint->name);
        self::assertSame('age >= 0', $constraint->expression);
        self::assertTrue($constraint->enforced);
    }

    public function testUserMetaStoresLoginAndAuthenticationMetadata(): void
    {
        $user = new UserMeta(
            name: 'app',
            host: 'localhost',
            plugin: 'caching_sha2_password',
            superuser: false,
            canLogin: true,
        );

        self::assertSame('app', $user->name);
        self::assertSame('localhost', $user->host);
        self::assertSame('caching_sha2_password', $user->plugin);
        self::assertFalse($user->superuser);
        self::assertTrue($user->canLogin);
    }

    public function testPartitionInfoStoresPortablePartitionConfiguration(): void
    {
        $partition = new PartitionInfo(
            name: 'orders_2026',
            schema: 'public',
            method: 'RANGE',
            expression: 'created_at',
            parentTable: 'orders',
            bound: 'FOR VALUES FROM (2026-01-01) TO (2027-01-01)',
        );

        self::assertSame('orders_2026', $partition->name);
        self::assertSame('public', $partition->schema);
        self::assertSame('RANGE', $partition->method);
        self::assertSame('created_at', $partition->expression);
        self::assertSame('orders', $partition->parentTable);
        self::assertSame('FOR VALUES FROM (2026-01-01) TO (2027-01-01)', $partition->bound);
    }

    public function testBackwardKeyMetaStoresReverseForeignKeyMappings(): void
    {
        $key = new BackwardKeyMeta(
            constraintName: 'orders_user_id_foreign',
            sourceTable: 'orders',
            sourceColumns: ['user_id'],
            targetColumns: ['id'],
        );

        self::assertSame('orders_user_id_foreign', $key->constraintName);
        self::assertSame('orders', $key->sourceTable);
        self::assertSame(['user_id'], $key->sourceColumns);
        self::assertSame(['id'], $key->targetColumns);
    }

    public function testProcessMetaStoresProcessListSnapshot(): void
    {
        $process = new ProcessMeta(
            id: 42,
            user: 'app',
            host: '127.0.0.1:54321',
            database: 'shop',
            command: 'Query',
            time: 12,
            state: 'active',
            query: 'SELECT * FROM users',
        );

        self::assertSame(42, $process->id);
        self::assertSame('app', $process->user);
        self::assertSame('127.0.0.1:54321', $process->host);
        self::assertSame('shop', $process->database);
        self::assertSame('Query', $process->command);
        self::assertSame(12, $process->time);
        self::assertSame('active', $process->state);
        self::assertSame('SELECT * FROM users', $process->query);
    }
}
