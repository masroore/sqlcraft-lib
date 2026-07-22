<?php
declare(strict_types=1);
namespace SQLCraft\Events;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Enums\QueryKind;
final class BeforeQueryExecuted extends InterceptionEvent
{
    public readonly ConnectionInterface $connection; public readonly string $queryType; public readonly QueryKind $kind; private string $sql; private array $params;
    /** @param array<string|int,mixed> $params */
    public function __construct(ConnectionInterface $connection,string $sql,array $params,string|QueryKind $queryType,?QueryKind $kind=null){$this->connection=$connection;$this->sql=$sql;$this->params=$params;$this->kind=$kind??($queryType instanceof QueryKind?$queryType:QueryKind::from(strtolower($queryType)));$this->queryType=$queryType instanceof QueryKind?strtoupper($queryType->value):$queryType;}
    public function getSql():string{return $this->sql;}
    /** @return array<string|int,mixed> */ public function getParams():array{return $this->params;}
    /** @param array<string|int,mixed> $params */ public function replaceSql(string $sql,array $params):void{$this->sql=$sql;$this->params=$params;}
}
