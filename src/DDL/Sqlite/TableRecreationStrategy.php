<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Sqlite;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\TableRecreationDefinitionInterface;
use SQLCraft\Contracts\DDL\TableRecreationMetadataProviderInterface;
use SQLCraft\Contracts\Execution\TransactionManagerInterface;
use SQLCraft\DDL\AlterTableBuilder;
use SQLCraft\DDL\CreateIndexBuilder;
use SQLCraft\DDL\CreateTableBuilder;
use SQLCraft\DDL\CreateTriggerBuilder;
use SQLCraft\DDL\Definition\TableRecreationDefinition;
use SQLCraft\DDL\DropTableBuilder;
use SQLCraft\Exceptions\ForeignKeyConstraintException;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final readonly class TableRecreationStrategy
{
    public function __construct(
        private TransactionManagerInterface $transactions,
        private TableRecreationMetadataProviderInterface $metadata,
    ) {
    }

    public function execute(ConnectionInterface $connection, AlterTableBuilder $builder): void
    {
        $this->transactions->transactional($connection, function (ConnectionInterface $connection) use ($builder): void {
            $original = $builder->getTable();
            $definition = $this->metadata->getDefinition($connection, $original);
            $temporary = new QualifiedName($this->temporaryName($original));
            $final = $this->finalDefinition($definition, $builder);

            $connection->execute('PRAGMA foreign_keys = OFF');
            (new CreateTableBuilder(
                table: $temporary,
                columns: $final->getColumns(),
                foreignKeys: $final->getForeignKeys(),
                checkConstraints: $final->getCheckConstraints(),
            ))->execute($connection);

            $columns = array_map(
                static fn (\SQLCraft\Contracts\DDL\ColumnDefinitionInterface $column): string => $connection->quoteIdentifier($column->getName()),
                $final->getColumns(),
            );
            $columnList = implode(', ', $columns);
            $connection->execute(
                'INSERT INTO ' . $connection->quoteIdentifier($temporary->object->name)
                . ' (' . $columnList . ') SELECT ' . $columnList
                . ' FROM ' . $connection->quoteIdentifier($original->object->name),
            );

            (new DropTableBuilder($original))->execute($connection);
            $connection->execute(
                'ALTER TABLE ' . $connection->quoteIdentifier($temporary->object->name)
                . ' RENAME TO ' . $connection->quoteIdentifier($original->object->name),
            );

            foreach ($final->getIndexes() as $index) {
                if ($index->getType() !== IndexType::PRIMARY) {
                    (new CreateIndexBuilder($original, $index))->execute($connection);
                }
            }
            foreach ($final->getTriggers() as $trigger) {
                (new CreateTriggerBuilder(
                    $trigger->getName(),
                    $original,
                    $trigger->getTiming(),
                    $trigger->getEvent(),
                    $trigger->getBody(),
                    $trigger->getDefiner(),
                    $trigger->getForEach(),
                ))->execute($connection);
            }

            $connection->execute('PRAGMA foreign_keys = ON');
            if ($connection->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new ForeignKeyConstraintException('Table recreation produced foreign-key violations.');
            }
        });
    }

    private function temporaryName(QualifiedName $original): Identifier
    {
        return new Identifier('_sqlcraft_recreate_' . $original->object->name . '_' . bin2hex(random_bytes(4)));
    }

    private function finalDefinition(TableRecreationDefinitionInterface $definition, AlterTableBuilder $builder): TableRecreationDefinition
    {
        $columns = $definition->getColumns();
        $dropColumns = array_fill_keys(array_map(static fn (Identifier $column): string => $column->name, $builder->getDropColumns()), true);
        $modifications = [];
        foreach ($builder->getModifyColumns() as [$new, $original]) {
            $modifications[$original->getName()] = $new;
        }

        $columns = array_values(array_filter(array_map(
            static function (\SQLCraft\Contracts\DDL\ColumnDefinitionInterface $column) use ($dropColumns, $modifications) {
                if (isset($dropColumns[$column->getName()])) {
                    return null;
                }

                return $modifications[$column->getName()] ?? $column;
            },
            $columns,
        )));
        foreach ($builder->getAddColumns() as [$column]) {
            $columns[] = $column;
        }

        $dropIndexes = array_fill_keys(array_map(static fn (Identifier $index): string => $index->name, $builder->getDropIndexes()), true);
        $indexes = array_values(array_filter(
            $definition->getIndexes(),
            static fn (\SQLCraft\Contracts\DDL\IndexDefinitionInterface $index): bool => !isset($dropIndexes[$index->getName()]),
        ));
        foreach ($builder->getAddIndexes() as $index) {
            $indexes[] = $index;
        }

        $dropForeignKeys = array_fill_keys(array_map(static fn (Identifier $key): string => $key->name, $builder->getDropForeignKeys()), true);
        $foreignKeys = array_values(array_filter(
            $definition->getForeignKeys(),
            static fn (\SQLCraft\Contracts\DDL\ForeignKeyDefinitionInterface $key): bool => !isset($dropForeignKeys[$key->getConstraintName()]),
        ));
        foreach ($builder->getAddForeignKeys() as $key) {
            $foreignKeys[] = $key;
        }

        $dropChecks = array_fill_keys(array_map(static fn (Identifier $key): string => $key->name, $builder->getDropCheckConstraints()), true);
        $checks = array_values(array_filter(
            $definition->getCheckConstraints(),
            static fn (\SQLCraft\Contracts\DDL\CheckConstraintDefinitionInterface $check): bool => !isset($dropChecks[$check->getName()]),
        ));
        foreach ($builder->getAddCheckConstraints() as $check) {
            $checks[] = $check;
        }

        return new TableRecreationDefinition($columns, $indexes, $foreignKeys, $checks, $definition->getTriggers());
    }
}
