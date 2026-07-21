<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SQLCraft\Query\StatementSplitter;

final class StatementSplitterTest extends TestCase
{
    public function testSplitsStatementsWithoutSplittingQuotedSemicolonsOrComments(): void
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

    public function testHandlesDelimiterDirectivesAndRestoresDefaultDelimiter(): void
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

    public function testRejectsEmptyDelimiter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new StatementSplitter())->split('SELECT 1', '');
    }
}
