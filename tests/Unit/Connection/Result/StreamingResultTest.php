<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection\Result;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\Result\StreamingResult;
use SQLCraft\Exceptions\StreamingResultException;

final class StreamingResultTest extends TestCase
{
    public function testStreamingResultConsumesRowsLazily(): void
    {
        $started = false;
        $result = new StreamingResult(static function () use (&$started): \Generator {
            $started = true;
            yield ['id' => 1, 'name' => 'Ada'];
            yield ['id' => 2, 'name' => 'Grace'];
        });

        self::assertTrue($result->isStreaming());
        self::assertFalse($started);
        self::assertSame(['id' => 1, 'name' => 'Ada'], $result->fetchAssoc());
        self::assertSame([2, 'Grace'], $result->fetchRow());
        self::assertNull($result->fetchAssoc());
    }

    public function testStreamingResultRejectsSeekAndCount(): void
    {
        $result = new StreamingResult(static function (): \Generator {
            yield ['id' => 1];
        });

        try {
            $result->seek(0);
            self::fail('Expected seek to be rejected.');
        } catch (StreamingResultException $exception) {
            self::assertSame('Streaming results cannot seek.', $exception->getMessage());
        }

        $this->expectException(StreamingResultException::class);
        $result->count();
    }

    public function testStreamingResultFetchesColumnsAndIteratesRemainingRows(): void
    {
        $result = new StreamingResult(static function (): \Generator {
            yield ['id' => 1, 'name' => 'Ada'];
            yield ['id' => 2, 'name' => 'Grace'];
        });

        self::assertSame([1, 2], $result->fetchColumn('id'));
        self::assertSame([], iterator_to_array($result));
    }
}
