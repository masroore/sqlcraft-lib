<?php
declare(strict_types=1);
namespace SQLCraft\Metadata;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\MetadataInspectorSetFactoryInterface;
final readonly class DefaultMetadataInspectorSetFactory implements MetadataInspectorSetFactoryInterface
{
    public function __construct(private MetadataFactoryInterface $factory) {}
    public function create(ConnectionInterface $connection): MetadataInspectorSet
    {
        return new MetadataInspectorSet(new ServerInspector($this->factory),new DatabaseInspector($this->factory),new TableInspector($this->factory),new ColumnInspector($this->factory),new IndexInspector($this->factory),new ForeignKeyInspector($this->factory),new ViewInspector($this->factory),new RoutineInspector($this->factory),new TriggerInspector($this->factory),new SequenceInspector($this->factory),new CheckConstraintInspector($this->factory),new UserInspector($this->factory),new PrivilegeInspector());
    }
}
