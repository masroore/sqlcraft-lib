<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Collections;

use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\AbstractImmutableCollection;
use SQLCraft\ValueObjects\Identifier;

/** @extends AbstractImmutableCollection<Identifier> */
final class IdentifierCollection extends AbstractImmutableCollection
{
    /** @param array<int|string, Identifier> $items */
    #[\Override]
    protected function create(array $items): static
    {
        return new self($items);
    }
}

final class AbstractImmutableCollectionTest extends TestCase
{
    public function test_it_counts_iterates_and_looks_up_items(): void
    {
        $users = new IdentifierCollection([
            'first' => new Identifier('users'),
            'second' => new Identifier('accounts'),
        ]);

        self::assertCount(2, $users);
        self::assertSame('users', $users->get('first')->name);
        self::assertSame('accounts', $users['second']->name);
        self::assertTrue(isset($users['first']));
        self::assertFalse(isset($users['missing']));
        self::assertSame(['first' => 'users', 'second' => 'accounts'], array_map(
            static fn (Identifier $identifier): string => $identifier->name,
            iterator_to_array($users),
        ));
    }

    public function test_it_rejects_missing_and_invalid_keys(): void
    {
        $users = new IdentifierCollection([new Identifier('users')]);

        $this->expectException(OutOfBoundsException::class);
        $users->get(5);
    }

    public function test_it_filters_without_mutating_the_original_collection(): void
    {
        $users = new IdentifierCollection([
            new Identifier('users'),
            new Identifier('accounts'),
        ]);

        $filtered = $users->filter(
            static fn (Identifier $identifier): bool => $identifier->name === 'accounts',
        );

        self::assertCount(2, $users);
        self::assertCount(1, $filtered);
        self::assertSame('accounts', $filtered->get(1)->name);
    }

    public function test_it_maps_items_to_a_list(): void
    {
        $users = new IdentifierCollection([
            'users' => new Identifier('users'),
            'accounts' => new Identifier('accounts'),
        ]);

        self::assertSame(
            ['USERS', 'ACCOUNTS'],
            $users->map(static fn (Identifier $identifier): string => strtoupper($identifier->name)),
        );
    }

    public function test_it_rejects_array_mutation(): void
    {
        $users = new IdentifierCollection([new Identifier('users')]);

        try {
            $users['other'] = new Identifier('accounts');
            self::fail('Expected collection assignment to fail.');
        } catch (LogicException $exception) {
            self::assertSame('Immutable collections cannot be modified.', $exception->getMessage());
        }

        try {
            unset($users['users']);
            self::fail('Expected collection removal to fail.');
        } catch (LogicException $exception) {
            self::assertSame('Immutable collections cannot be modified.', $exception->getMessage());
        }
    }
}
