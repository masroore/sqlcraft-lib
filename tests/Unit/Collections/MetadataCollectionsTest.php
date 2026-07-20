<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Collections;

use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\CharsetCollection;
use SQLCraft\Collections\CheckConstraintCollection;
use SQLCraft\Collections\CollationCollection;
use SQLCraft\Collections\PartitionCollection;
use SQLCraft\Collections\ProcessCollection;
use SQLCraft\Collections\QualifiedNameCollection;
use SQLCraft\Collections\SchemaCollection;
use SQLCraft\Collections\SequenceCollection;
use SQLCraft\Collections\UserCollection;
use SQLCraft\Collections\ViewCollection;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\PartitionInfo;
use SQLCraft\DTO\ProcessMeta;
use SQLCraft\DTO\SchemaMeta;
use SQLCraft\DTO\SequenceMeta;
use SQLCraft\DTO\UserMeta;
use SQLCraft\DTO\ViewMeta;
use SQLCraft\ValueObjects\Charset;
use SQLCraft\ValueObjects\Collation;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class MetadataCollectionsTest extends TestCase
{
    public function testMetadataCollectionWrappersPreserveTheirItemTypes(): void
    {
        $schema = new SchemaMeta('public', null, 'postgres');
        $sequence = new SequenceMeta('orders_id_seq', 'public', 1, 1, 999, 1, false, 'orders', 'id');
        $partition = new PartitionInfo('orders_2026', 'public', 'RANGE', 'created_at', 'orders', "FROM ('2026-01-01') TO ('2027-01-01')");
        $process = new ProcessMeta(7, 'app', null, 'shop', 'Query', 2, 'active', 'SELECT 1');
        $view = new ViewMeta('order_summary', 'public', 'SELECT 1', false);
        $check = new CheckConstraintMeta('orders_total_check', 'total >= 0', true);
        $user = new UserMeta('app', null, null, false, true);
        $qualifiedName = new QualifiedName(new Identifier('orders'));
        $charset = new Charset('utf8mb4');
        $collation = new Collation('utf8mb4_unicode_ci');

        self::assertSame($schema, (new SchemaCollection(['public' => $schema]))->get('public'));
        self::assertSame($sequence, (new SequenceCollection(['orders_id_seq' => $sequence]))->get('orders_id_seq'));
        self::assertSame($partition, (new PartitionCollection(['orders_2026' => $partition]))->get('orders_2026'));
        self::assertSame($process, (new ProcessCollection([7 => $process]))->get(7));
        self::assertSame($view, (new ViewCollection(['order_summary' => $view]))->get('order_summary'));
        self::assertSame($check, (new CheckConstraintCollection(['orders_total_check' => $check]))->get('orders_total_check'));
        self::assertSame($user, (new UserCollection(['app' => $user]))->get('app'));
        self::assertSame($qualifiedName, (new QualifiedNameCollection(['orders' => $qualifiedName]))->get('orders'));
        self::assertSame($charset, (new CharsetCollection(['utf8mb4' => $charset]))->get('utf8mb4'));
        self::assertSame($collation, (new CollationCollection(['utf8mb4_unicode_ci' => $collation]))->get('utf8mb4_unicode_ci'));
    }

    public function testFilteringKeepsConcreteMetadataCollectionType(): void
    {
        $schemas = new SchemaCollection([
            'public' => new SchemaMeta('public', null, 'postgres'),
            'internal' => new SchemaMeta('internal', null, 'postgres'),
        ]);

        $filtered = $schemas->filter(static fn (SchemaMeta $schema): bool => $schema->name === 'public');

        self::assertSame(SchemaCollection::class, get_class($filtered));
        self::assertSame('public', $filtered->get('public')->name);
        self::assertFalse(isset($filtered['internal']));
    }
}
