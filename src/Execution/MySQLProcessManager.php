<?php
declare(strict_types=1); namespace SQLCraft\Execution; final class MySQLProcessManager extends AbstractProcessManager { protected function killSql(int $id): string { return sprintf('KILL %d', $id); } protected function killParams(int $id): array { return []; } }
