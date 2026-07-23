<?php

declare(strict_types=1);

// Bootstrap-only extension composition. Replace the example adapters with application implementations.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\ArrayCredentialProvider;
use SQLCraft\Connection\CredentialProviderChain;
use SQLCraft\Connection\EnvCredentialProvider;
use SQLCraft\Contracts\Connection\ConnectionInitializerInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryInterceptorInterface;
use SQLCraft\Execution\QueryRequest;
use SQLCraft\SQLCraftBuilder;
use SQLCraft\ValueObjects\ConnectionParameters;

final readonly class ExampleInitializer implements ConnectionInitializerInterface
{
    public function initialize(ConnectionInterface $connection, ConnectionParameters $parameters): void
    {
        // Driver-specific setup belongs here.
    }
}

final readonly class ExampleInterceptor implements QueryInterceptorInterface
{
    public function intercept(QueryRequest $request): QueryRequest
    {
        return $request;
    }
}

// The custom providers are placeholders for application adapters.
$credentials = new CredentialProviderChain([
    new ArrayCredentialProvider([]),
    new EnvCredentialProvider,
]);

$builder = SQLCraftBuilder::defaults()
    ->credentials($credentials)
    ->initializeConnection(new ExampleInitializer)
    ->interceptQueries(new ExampleInterceptor)
    ->queryHistory(null)
    ->metadataCache(null);

$factory = $builder->build();

// A real application supplies driver-specific parameters before opening a session.
$parameters = new ConnectionParameters(driver: 'sqlite', database: ':memory:');
$session = $factory->session($parameters);
$session->query('SELECT 1');
