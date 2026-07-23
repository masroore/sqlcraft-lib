<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use PDO;
use PDOException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Exceptions\ConnectionFailedException;
use SQLCraft\Exceptions\OperationCancelledException;
use SQLCraft\Support\SecretRedactor;
use SQLCraft\ValueObjects\ConnectionParameters;

/** @internal */
final class PdoConnectionFactory implements PdoConnectionFactoryInterface
{
    public function __construct(
        private readonly PdoExceptionTranslator $translator,
        private readonly ?ConnectionEventDispatcherInterface $events = null,
        private readonly bool $emitLifecycleEvents = true,
    ) {
    }

    #[\Override]
    public function connect(
        string $dsn,
        ConnectionParameters $parameters,
        PlatformInterface $platform,
        ?string $name = null,
    ): ConnectionInterface {
        $connectionName = $name ?? $platform->getName();
        $startedAt = hrtime(true);
        $cancelReason = $this->emitLifecycleEvents ? $this->events?->beforeConnectionOpened($connectionName, $parameters) : null;
        if ($cancelReason !== null) {
            throw new OperationCancelledException($cancelReason);
        }
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];
            if ($platform->getName() === 'sqlserver' && defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')) {
                /** @var int $attribute */
                $attribute = constant('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE');
                $options[$attribute] = true;
            }

            $pdo = new PDO(
                $dsn,
                $parameters->username,
                $parameters->password,
                $options,
            );
        } catch (PDOException $exception) {
            if ($this->emitLifecycleEvents) {
                $this->events?->connectionFailed($connectionName, $platform->getName(), $exception);
            }
            throw new ConnectionFailedException(
                'Database connection failed.',
                host: $parameters->host ?? '',
                driver: SecretRedactor::dsn($dsn),
                previous: $exception,
            );
        }

        $connection = new PdoConnection($pdo, $platform, $this->translator, $name, $parameters->database, $this->events);
        if ($this->emitLifecycleEvents) {
            $this->events?->connectionOpened($connectionName, $platform->getName(), $parameters->host, $parameters->database, (hrtime(true) - $startedAt) / 1_000_000, $connection);
        }

        return $connection;
    }
}
