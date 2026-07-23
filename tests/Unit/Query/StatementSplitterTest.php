<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SQLCraft\Query\StatementSplitter;

final class StatementSplitterTest extends TestCase
{
    public function test_splits_statements_without_splitting_quoted_semicolons_or_comments(): void
    {
        $sql = <<<'SQL'
            SELECT 'a;b'; -- keep ; in comment
            /* block ; comment */ INSERT INTO users(name) VALUES ("x;y");
            SQL;

        self::assertSame(
            ["SELECT 'a;b'", "-- keep ; in comment\n/* block ; comment */ INSERT INTO users(name) VALUES (\"x;y\")"],
            (new StatementSplitter())->split($sql)->statements,
        );
    }

    public function test_handles_delimiter_directives_and_restores_default_delimiter(): void
    {
        $sql = <<<'SQL'
            DELIMITER $$
            CREATE PROCEDURE p()
            BEGIN
                SELECT 1;
                SELECT 'two;';
            END$$
            DELIMITER ;
            SELECT 2;
            SQL;

        self::assertSame(
            ["CREATE PROCEDURE p()\nBEGIN\n    SELECT 1;\n    SELECT 'two;';\nEND", 'SELECT 2'],
            (new StatementSplitter())->split($sql)->statements,
        );
    }

    public function test_ignores_comment_only_trailing_input(): void
    {
        self::assertSame([], (new StatementSplitter())->split("-- trailing comment\n/* another comment */")->statements);
    }

    public function test_rejects_empty_delimiter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new StatementSplitter())->split('SELECT 1', '');
    }
}
