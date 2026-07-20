<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PDOException;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Exceptions\AuthenticationException;
use SQLCraft\Exceptions\ConnectionLostException;
use SQLCraft\Exceptions\ConstraintViolationException;
use SQLCraft\Exceptions\DeadlockException;
use SQLCraft\Exceptions\ForeignKeyConstraintException;
use SQLCraft\Exceptions\QueryException;
use SQLCraft\Exceptions\SyntaxErrorException;
use SQLCraft\Exceptions\UniqueConstraintException;

final class PdoExceptionTranslatorTest extends TestCase
{
    public function testItTranslatesAuthenticationAndConnectionFailures(): void
    {
        $translator = new PdoExceptionTranslator();

        $authentication = $translator->translate($this->pdoException('28000', 1045), '');
        $connection = $translator->translate($this->pdoException('08006', 7), 'SELECT 1');

        self::assertInstanceOf(AuthenticationException::class, $authentication);
        self::assertSame('Database authentication failed.', $authentication->getMessage());
        self::assertInstanceOf(ConnectionLostException::class, $connection);
        self::assertSame('Database connection was lost.', $connection->getMessage());
    }

    public function testItTranslatesQueryAndConstraintFailures(): void
    {
        $translator = new PdoExceptionTranslator();
        $previous = $this->pdoException('23000', 1062, 'sensitive native message');
        $sql = 'INSERT INTO users (email) VALUES (?)';

        $unique = $translator->translate($previous, $sql);
        $foreignKey = $translator->translate($this->pdoException('23503', 0), $sql);
        $constraint = $translator->translate($this->pdoException('23000', 999), $sql);
        $syntax = $translator->translate($this->pdoException('42601', 0), 'SELECT FRM users');
        $deadlock = $translator->translate($this->pdoException('40001', 0), 'UPDATE users SET name = ?');

        self::assertInstanceOf(UniqueConstraintException::class, $unique);
        self::assertSame($previous, $unique->getPrevious());
        self::assertSame($sql, $unique->sql);
        self::assertSame('Unique constraint violation.', $unique->getMessage());
        self::assertInstanceOf(ForeignKeyConstraintException::class, $foreignKey);
        self::assertInstanceOf(ConstraintViolationException::class, $constraint);
        self::assertInstanceOf(SyntaxErrorException::class, $syntax);
        self::assertInstanceOf(DeadlockException::class, $deadlock);
    }

    public function testItUsesAConcreteGenericQueryExceptionForUnknownFailures(): void
    {
        $translator = new PdoExceptionTranslator();
        $previous = $this->pdoException('HY000', 999, 'sensitive native message');

        $exception = $translator->translate($previous, 'SELECT * FROM users');

        self::assertInstanceOf(QueryException::class, $exception);
        self::assertSame('SQL execution failed.', $exception->getMessage());
        self::assertSame('SELECT * FROM users', $exception->sql);
        self::assertSame($previous, $exception->getPrevious());
        self::assertStringNotContainsString('sensitive native message', $exception->getMessage());
    }

    private function pdoException(string $sqlState, int $nativeCode, string $message = 'driver failure'): PDOException
    {
        $exception = new PDOException($message);
        $exception->errorInfo = [$sqlState, $nativeCode, $message];

        return $exception;
    }
}
