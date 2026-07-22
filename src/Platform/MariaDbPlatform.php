<?php

declare(strict_types=1);

namespace SQLCraft\Platform;

use SQLCraft\Capabilities\Capability;
use SQLCraft\ValueObjects\Identifier;

final class MariaDbPlatform extends MySQLPlatform
{
    #[\Override]
    public function getName(): string
    {
        return 'mariadb';
    }

    #[\Override]
    public function getFlavor(): string
    {
        return 'maria';
    }

    #[\Override]
    public function getSequencesSql(?string $schema = null): string
    {
        return "SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE ENGINE = 'SEQUENCE'"
            . ($schema === null ? '' : ' AND TABLE_SCHEMA = ' . $this->quoteValue($schema));
    }

    /**
     * @return array{always: list<Capability>, versioned: list<array{0: Capability, 1: array{0: int, 1: int, 2: int}}>}
     */
    #[\Override]
    protected function buildCapabilityMatrix(): array
    {
        $matrix = parent::buildCapabilityMatrix();
        $matrix['always'][] = Capability::DescendingIndexes;
        $matrix['versioned'] = [
            [Capability::GeneratedColumns, [5, 2, 0]],
            [Capability::CheckConstraints, [10, 2, 1]],
            [Capability::Sequence, [10, 3, 0]],
        ];

        return $matrix;
    }

    #[\Override]
    public function renderCreateSequenceStatement(
        Identifier $name,
        int $start,
        int $increment,
        ?int $min,
        ?int $max,
        bool $cycle,
        ?int $cache,
    ): string {
        $sql = 'CREATE SEQUENCE ' . $this->quoteIdentifier($name)
            . ' START WITH ' . $start . ' INCREMENT BY ' . $increment;
        if ($min !== null) {
            $sql .= ' MINVALUE ' . $min;
        }
        if ($max !== null) {
            $sql .= ' MAXVALUE ' . $max;
        }
        if ($cycle) {
            $sql .= ' CYCLE';
        }
        if ($cache !== null) {
            $sql .= ' CACHE ' . $cache;
        }

        return $sql;
    }

    #[\Override]
    public function renderDropSequenceStatement(Identifier $name, bool $ifExists): string
    {
        return 'DROP SEQUENCE' . ($ifExists ? ' IF EXISTS' : '') . ' ' . $this->quoteIdentifier($name);
    }
}
