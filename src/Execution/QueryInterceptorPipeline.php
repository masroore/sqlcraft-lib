<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryInterceptorInterface;
use SQLCraft\Enums\QueryKind;
use SQLCraft\Exceptions\ExtensionConfigurationException;

final readonly class QueryInterceptorPipeline
{
    /** @param list<QueryInterceptorInterface> $interceptors */
    public function __construct(private array $interceptors = []) {}

    /** @param array<string|int, mixed> $params */
    public function process(
        ConnectionInterface $connection,
        string $sql,
        array $params,
        QueryKind $kind,
        ?string $originalSql = null,
    ): QueryRequest {
        $initial = new QueryRequest($connection, $originalSql ?? $sql, $sql, $params, $kind);
        $current = $initial;

        foreach ($this->interceptors as $interceptor) {
            $next = $interceptor->intercept($current);
            if ($next->connection !== $initial->connection || $next->originalSql !== $initial->originalSql || $next->kind !== $initial->kind) {
                throw new ExtensionConfigurationException('Query interceptor changed immutable request provenance.');
            }
            if (trim($next->sql) === '') {
                throw new ExtensionConfigurationException('Query interceptor returned empty SQL.');
            }

            $keys = array_keys($next->params);
            $hasInt = false;
            $hasString = false;
            foreach ($keys as $key) {
                $hasInt = $hasInt || is_int($key);
                $hasString = $hasString || is_string($key);
            }
            if ($hasInt && $hasString) {
                throw new ExtensionConfigurationException('Query parameters must use either positional or named keys.');
            }
            $current = $next;
        }

        return $current;
    }
}
