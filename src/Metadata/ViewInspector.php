<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use InvalidArgumentException;
use SQLCraft\Collections\ViewCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\ViewInspectorInterface;
use SQLCraft\Exceptions\ObjectNotFoundException;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final class ViewInspector implements ViewInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory) {}

    #[\Override]
    public function getViews(ConnectionInterface $conn, ?string $schema = null): ViewCollection
    {
        return $this->collect($conn, $conn->getPlatform()->introspection()->getViewsSql($schema));
    }

    #[\Override]
    public function getViewDefinition(ConnectionInterface $conn, QualifiedName $view): string
    {
        $row = $conn->query($conn->getPlatform()->introspection()->getViewDefinitionSql($view))->fetchAssoc();
        if ($row === null) {
            throw new ObjectNotFoundException(
                sprintf('View %s does not exist.', $view->object->name),
                $view->object->name,
            );
        }

        foreach (['definition', 'view_definition', 'sql'] as $key) {
            /** @var bool|float|int|string|null $value */
            $value = $row[$key] ?? null;
            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        throw new InvalidArgumentException('View metadata is missing its definition.');
    }

    #[\Override]
    public function getMaterializedViews(ConnectionInterface $conn, ?string $schema = null): ViewCollection
    {
        return $this->collect($conn, $conn->getPlatform()->introspection()->getMaterializedViewsSql($schema));
    }

    private function collect(ConnectionInterface $conn, string $sql): ViewCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($sql)->fetchAll();
        $views = [];

        foreach ($rows as $row) {
            $view = $this->factory->createViewMeta($row);
            $views[$view->name] = $view;
        }

        return new ViewCollection($views);
    }
}
