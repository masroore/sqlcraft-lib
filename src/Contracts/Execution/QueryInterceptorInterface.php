<?php
declare(strict_types=1);
namespace SQLCraft\Contracts\Execution;
use SQLCraft\Execution\QueryRequest;
interface QueryInterceptorInterface { public function intercept(QueryRequest $request): QueryRequest; }
