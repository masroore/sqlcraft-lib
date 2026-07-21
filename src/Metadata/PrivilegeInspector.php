<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Collections\PrivilegeCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\PrivilegeInspectorInterface;
use SQLCraft\ValueObjects\Privilege;
use SQLCraft\ValueObjects\QualifiedName;

final class PrivilegeInspector implements PrivilegeInspectorInterface
{
    #[\Override]
    public function getPrivileges(ConnectionInterface $conn, ?string $user = null, ?QualifiedName $object = null): PrivilegeCollection
    {
        $conn->getPlatform()->getCapabilitySet($conn->getServerVersion())->require(Capability::Privileges);
        $platform = $conn->getPlatformName();
        $sql = match ($platform) {
            'mysql', 'mariadb' => 'SELECT PRIVILEGE_TYPE AS privilege_name FROM INFORMATION_SCHEMA.TABLE_PRIVILEGES'
                . $this->whereMysql($user, $object),
            'pgsql' => 'SELECT privilege_type AS privilege_name FROM information_schema.role_table_grants'
                . $this->wherePgsql($user, $object),
            'sqlserver' => 'SELECT permission_name AS privilege_name FROM sys.database_permissions',
            default => throw CapabilityNotSupportedException::for(Capability::Privileges, $platform),
        };
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($sql)->fetchAll();
        $privileges = [];
        foreach ($rows as $row) {
            $name = $row['privilege_name'] ?? $row['PRIVILEGE_NAME'] ?? null;
            if (is_string($name) && $name !== '') {
                $privileges[$name] = new Privilege($name);
            }
        }

        return new PrivilegeCollection($privileges);
    }

    private function whereMysql(?string $user, ?QualifiedName $object): string
    {
        $conditions = [];
        if ($user !== null) {
            $conditions[] = " GRANTEE = '" . str_replace("'", "''", $user) . "'";
        }
        if ($object !== null) {
            $conditions[] = " TABLE_NAME = '" . str_replace("'", "''", $object->object->name) . "'";
        }

        return $conditions === [] ? '' : ' WHERE' . implode(' AND', $conditions);
    }

    private function wherePgsql(?string $user, ?QualifiedName $object): string
    {
        $conditions = [];
        if ($user !== null) {
            $conditions[] = " grantee = '" . str_replace("'", "''", $user) . "'";
        }
        if ($object !== null) {
            $conditions[] = " table_name = '" . str_replace("'", "''", $object->object->name) . "'";
        }

        return $conditions === [] ? '' : ' WHERE' . implode(' AND', $conditions);
    }
}
