<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use PDO;
use PDOException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Exceptions\ConnectionFailedException;
use SQLCraft\ValueObjects\ConnectionParameters;

/** @internal */
final class PdoConnectionFactory implements PdoConnectionFactoryInterface
{
    public function __construct(private readonly PdoExceptionTranslator $translator)
    {
    }

    #[\Override]
    public function connect(
        string $dsn,
        ConnectionParameters $parameters,
        PlatformInterface $platform,
        ?string $name = null,
    ): ConnectionInterface {
        try {
            $pdo = new PDO(
                $dsn,
                $parameters->username,
                $parameters->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new ConnectionFailedException(
                'Database connection failed.',
                host: $parameters->host ?? '',
                driver: $dsn,
                previous: $exception,
            );
        }

        return new PdoConnection($pdo, $platform, $this->translator, $name);
    }
}
