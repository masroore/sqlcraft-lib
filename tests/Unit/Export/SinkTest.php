<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\Export\ResourceSink;
use SQLCraft\Export\StringBufferSink;

final class SinkTest extends TestCase
{
    public function testStringBufferAccumulatesChunks(): void
    {
        $sink = new StringBufferSink();
        $sink->write('one');
        $sink->write('two');
        $sink->flush();
        $sink->close();

        self::assertSame('onetwo', $sink->contents());
    }

    public function testResourceSinkWritesFlushesAndClosesResource(): void
    {
        $resource = fopen('php://memory', 'w+');
        self::assertIsResource($resource);
        $sink = new ResourceSink($resource);
        $sink->write('export');
        $sink->flush();
        rewind($resource);

        self::assertSame('export', stream_get_contents($resource));
        $sink->close();
        $this->expectException(\RuntimeException::class);
        $sink->write('after-close');
    }

    public function testResourceSinkRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $resource = fopen('php://memory', 'w+');
        self::assertIsResource($resource);
        fclose($resource);
        new ResourceSink($resource);
    }
}
