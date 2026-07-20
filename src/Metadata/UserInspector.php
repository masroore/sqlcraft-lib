<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Collections\UserCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\UserInspectorInterface;

/** @internal */
final class UserInspector implements UserInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory)
    {
    }

    #[\Override]
    public function getUsers(ConnectionInterface $conn): UserCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getUsersSql())->fetchAll();
        $users = [];

        foreach ($rows as $row) {
            $user = $this->factory->createUserMeta($row);
            $users[$user->name] = $user;
        }

        return new UserCollection($users);
    }
}
