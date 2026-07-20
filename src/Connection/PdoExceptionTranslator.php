<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use PDOException;
use SQLCraft\Exceptions\AuthenticationException;
use SQLCraft\Exceptions\ConnectionLostException;
use SQLCraft\Exceptions\ConstraintViolationException;
use SQLCraft\Exceptions\DeadlockException;
use SQLCraft\Exceptions\ForeignKeyConstraintException;
use SQLCraft\Exceptions\QueryException;
use SQLCraft\Exceptions\SQLCraftException;
use SQLCraft\Exceptions\SyntaxErrorException;
use SQLCraft\Exceptions\UniqueConstraintException;

/** @internal */
final class PdoExceptionTranslator
{
    public function translate(PDOException $exception, string $sql = ''): SQLCraftException
    {
        $sqlState = $this->sqlState($exception);
        $nativeCode = $this->nativeCode($exception);
        $previous = $exception;

        if (str_starts_with($sqlState, '28')) {
            return new AuthenticationException('Database authentication failed.', code: $nativeCode, previous: $previous);
        }

        if (str_starts_with($sqlState, '08')) {
            return new ConnectionLostException('Database connection was lost.', code: $nativeCode, previous: $previous);
        }

        if ($sqlState === '40001' || in_array($nativeCode, [1205, 1213], true)) {
            return new DeadlockException('Database transaction deadlock detected.', $sql, $nativeCode, $previous);
        }

        if ($sqlState === '23505' || in_array($nativeCode, [19, 1062, 1169, 2627, 2601], true)) {
            return new UniqueConstraintException(
                'Unique constraint violation.',
                $sql,
                code: $nativeCode,
                previous: $previous,
            );
        }

        if ($sqlState === '23503' || in_array($nativeCode, [1451, 1452, 547], true)) {
            return new ForeignKeyConstraintException(
                'Foreign-key constraint violation.',
                $sql,
                code: $nativeCode,
                previous: $previous,
            );
        }

        if (str_starts_with($sqlState, '23')) {
            return new ConstraintViolationException(
                'Database constraint violation.',
                $sql,
                code: $nativeCode,
                previous: $previous,
            );
        }

        if ($sqlState === '42601' || $nativeCode === 1064) {
            return new SyntaxErrorException($sql, 'SQL syntax error.', $nativeCode, $previous);
        }

        return new QueryException('SQL execution failed.', $sql, $nativeCode, $previous);
    }

    private function sqlState(PDOException $exception): string
    {
        if (is_array($exception->errorInfo) && isset($exception->errorInfo[0]) && is_string($exception->errorInfo[0])) {
            return $exception->errorInfo[0];
        }

        $code = $exception->getCode();

        return is_string($code) ? $code : '';
    }

    private function nativeCode(PDOException $exception): int
    {
        if (is_array($exception->errorInfo) && isset($exception->errorInfo[1])) {
            if (is_int($exception->errorInfo[1])) {
                return $exception->errorInfo[1];
            }

            if (is_string($exception->errorInfo[1]) && is_numeric($exception->errorInfo[1])) {
                return (int) $exception->errorInfo[1];
            }
        }

        return is_int($exception->getCode()) ? $exception->getCode() : 0;
    }
}
