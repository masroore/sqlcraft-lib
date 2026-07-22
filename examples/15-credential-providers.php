<?php

declare(strict_types=1);

// Resolve connection secrets from an in-memory map or environment variables.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\ArrayCredentialProvider;
use SQLCraft\Connection\EnvCredentialProvider;
use SQLCraft\ValueObjects\Credential;

// Named profiles → Credential DTO.
$arrayProvider = new ArrayCredentialProvider([
    'production' => new Credential('admin', 'secret123'),
    'staging' => new Credential('stage_user', 'stage_pass'),
]);
$credential = $arrayProvider->resolve('production');
printf("Array: user=%s, pass=%s\n", $credential->username ?? 'null', $credential->password ?? 'null');

// EnvCredentialProvider looks up {PREFIX}{NAME}_USERNAME / _PASSWORD.
putenv('SQLCRAFT_TESTDB_USERNAME=env_user');
putenv('SQLCRAFT_TESTDB_PASSWORD=env_secret');
$credential = (new EnvCredentialProvider('SQLCRAFT_'))->resolve('testdb');
printf("Env: user=%s, pass=%s\n", $credential->username ?? 'null', $credential->password ?? 'null');
