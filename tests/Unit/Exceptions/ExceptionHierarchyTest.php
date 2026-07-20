<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SQLCraft\Exceptions\AuthenticationException;
use SQLCraft\Exceptions\CapabilityException;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Exceptions\ConnectionException;
use SQLCraft\Exceptions\ConnectionFailedException;
use SQLCraft\Exceptions\ConnectionLostException;
use SQLCraft\Exceptions\ConstraintViolationException;
use SQLCraft\Exceptions\DeadlockException;
use SQLCraft\Exceptions\DriverException;
use SQLCraft\Exceptions\DriverMisconfiguredException;
use SQLCraft\Exceptions\DriverNotFoundException;
use SQLCraft\Exceptions\ExportFailedException;
use SQLCraft\Exceptions\ForeignKeyConstraintException;
use SQLCraft\Exceptions\ImportExportException;
use SQLCraft\Exceptions\ImportFailedException;
use SQLCraft\Exceptions\InsufficientPrivilegesException;
use SQLCraft\Exceptions\MetadataException;
use SQLCraft\Exceptions\ObjectNotFoundException;
use SQLCraft\Exceptions\QueryException;
use SQLCraft\Exceptions\SQLCraftException;
use SQLCraft\Exceptions\SecurityException;
use SQLCraft\Exceptions\SyntaxErrorException;
use SQLCraft\Exceptions\UniqueConstraintException;
use ReflectionClass;
use RuntimeException;

final class ExceptionHierarchyTest extends TestCase
{
    #[DataProvider('concreteExceptionClasses')]
    public function testConcreteExceptionsAreFinalAndTyped(string $class): void
    {
        /** @var class-string<SQLCraftException> $class */
        $reflection = new ReflectionClass($class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * @return list<array{class-string<SQLCraftException>}>
     */
    public static function concreteExceptionClasses(): array
    {
        return [
            [AuthenticationException::class],
            [CapabilityNotSupportedException::class],
            [ConnectionFailedException::class],
            [ConnectionLostException::class],
            [DeadlockException::class],
            [DriverMisconfiguredException::class],
            [DriverNotFoundException::class],
            [ExportFailedException::class],
            [ForeignKeyConstraintException::class],
            [ImportFailedException::class],
            [InsufficientPrivilegesException::class],
            [ObjectNotFoundException::class],
            [SyntaxErrorException::class],
            [UniqueConstraintException::class],
        ];
    }

    public function testSyntaxErrorPreservesSqlAndPreviousException(): void
    {
        $previous = new RuntimeException('native failure');
        $exception = new SyntaxErrorException('SELECT * FRM users', 'syntax error', previous: $previous);

        self::assertSame('SELECT * FRM users', $exception->sql);
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame('syntax error', $exception->getMessage());
    }

    public function testConnectionExceptionCarriesHostAndDriver(): void
    {
        $exception = new ConnectionFailedException('connection failed', 'db.example.test', 'pgsql');

        self::assertSame('db.example.test', $exception->host);
        self::assertSame('pgsql', $exception->driver);
    }

    public function testConstraintExceptionCarriesSqlConstraintAndTable(): void
    {
        $exception = new UniqueConstraintException(
            'duplicate value',
            'INSERT INTO users (email) VALUES (:email)',
            'users_email_key',
            'users',
        );

        self::assertSame('INSERT INTO users (email) VALUES (:email)', $exception->sql);
        self::assertSame('users_email_key', $exception->constraintName);
        self::assertSame('users', $exception->table);
    }

    public function testDeadlockIsAlwaysRetryable(): void
    {
        self::assertTrue((new DeadlockException('deadlock'))->retryable);
    }

    public function testCapabilityExceptionFactoryCarriesScalarContext(): void
    {
        $exception = CapabilityNotSupportedException::for(Capability::Trigger, 'sqlite', '3.45');

        self::assertSame(Capability::Trigger, $exception->capability);
        self::assertSame('sqlite', $exception->platform);
        self::assertSame('3.45', $exception->version);
        self::assertSame('Capability not supported: trigger on sqlite 3.45.', $exception->getMessage());
    }

    public function testOtherTypedPayloadsAreExposed(): void
    {
        $object = new ObjectNotFoundException('table missing', 'public.users');
        $privilege = new InsufficientPrivilegesException('denied', 'SELECT', 'public.users');
        $driver = new DriverNotFoundException('driver missing', 'mysql');
        $misconfigured = new DriverMisconfiguredException('driver misconfigured', 'pgsql');
        $import = new ImportFailedException('import failed', 4, 12);
        $export = new ExportFailedException('export failed', 7, 20);

        self::assertSame('public.users', $object->qualifiedName);
        self::assertSame('SELECT', $privilege->privilege);
        self::assertSame('public.users', $privilege->object);
        self::assertSame('mysql', $driver->driver);
        self::assertSame('pgsql', $misconfigured->driver);
        self::assertSame(4, $import->statementIndex);
        self::assertSame(12, $import->rowIndex);
        self::assertSame(7, $export->statementIndex);
        self::assertSame(20, $export->rowIndex);
    }

    public function testHierarchyIntermediateTypesAreAbstract(): void
    {
        foreach ([
            SQLCraftException::class,
            ConnectionException::class,
            QueryException::class,
            ConstraintViolationException::class,
            MetadataException::class,
            CapabilityException::class,
            SecurityException::class,
            DriverException::class,
            ImportExportException::class,
        ] as $class) {
            self::assertTrue((new ReflectionClass($class))->isAbstract());
        }
    }
}
