<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\MetadataInspectorSetFactoryInterface;
use SQLCraft\Exceptions\ExtensionConfigurationException;

/** @internal Legacy bare-driver registrations must provide metadata explicitly. */
final class UnavailableMetadataInspectorSetFactory implements MetadataInspectorSetFactoryInterface
{
    #[\Override]
    public function create(ConnectionInterface $connection): MetadataInspectorSet
    {
        throw new ExtensionConfigurationException(sprintf(
            'Driver %s has no metadata inspector set factory.',
            $connection->getPlatformName(),
        ));
    }
}
