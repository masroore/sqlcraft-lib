<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\DTO\TableStatus;

final class TopologicalTableSorter
{
    /**
     * @param iterable<TableStatus> $tables
     * @param callable(TableStatus): iterable<string> $dependencies
     * @return array{tables: list<TableStatus>, cycle: bool}
     */
    public function sort(iterable $tables, callable $dependencies): array
    {
        $ordered = is_array($tables) ? array_values($tables) : iterator_to_array($tables, false);
        $byName = [];
        foreach ($ordered as $table) {
            $byName[$this->key($table)] = $table;
        }
        $edges = [];
        $indegree = array_fill_keys(array_keys($byName), 0);
        foreach ($ordered as $table) {
            $key = $this->key($table);
            foreach ($dependencies($table) as $dependency) {
                $dependencyKey = $this->normalize($dependency, $table->schema);
                if (!isset($byName[$dependencyKey]) || $dependencyKey === $key) {
                    continue;
                }
                $edges[$dependencyKey][] = $key;
                $indegree[$key]++;
            }
        }
        $queue = [];
        foreach ($ordered as $table) {
            if ($indegree[$this->key($table)] === 0) {
                $queue[] = $this->key($table);
            }
        }
        $result = [];
        while ($queue !== []) {
            $key = array_shift($queue);
            $result[] = $byName[$key];
            foreach ($edges[$key] ?? [] as $child) {
                if (--$indegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }
        if (count($result) !== count($ordered)) {
            return ['tables' => $ordered, 'cycle' => true];
        }
        return ['tables' => $result, 'cycle' => false];
    }

    private function key(TableStatus $table): string
    {
        return $this->normalize($table->name, $table->schema);
    }

    private function normalize(string $table, ?string $schema): string
    {
        return strtolower(($schema === null ? '' : $schema . '.') . $table);
    }
}
