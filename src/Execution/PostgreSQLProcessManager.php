<?php
declare(strict_types=1); namespace SQLCraft\Execution; final class PostgreSQLProcessManager extends AbstractProcessManager { protected function killSql(int $id): string { return 'SELECT pg_terminate_backend(?)'; } protected function killParams(int $id): array { return [$id]; } }
