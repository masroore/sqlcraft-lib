<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$contractTests = glob($root . '/tests/Contract/*Test.php') ?: [];

printf("SQLCraft contract-test report\n");
printf("Contract test files: %d\n", count($contractTests));
