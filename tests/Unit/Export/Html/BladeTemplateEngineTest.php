<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export\Html;

use PHPUnit\Framework\TestCase;
use SQLCraft\Export\Html\BladeTemplateEngine;

final class BladeTemplateEngineTest extends TestCase
{
    public function test_escaped_output(): void
    {
        $engine = new BladeTemplateEngine();
        $out = $engine->render('{{ $var }}', ['var' => '<script>']);

        self::assertSame('&lt;script&gt;', $out);
    }

    public function test_raw_output(): void
    {
        $engine = new BladeTemplateEngine();
        $out = $engine->render('{!! $var !!}', ['var' => '<b>ok</b>']);

        self::assertSame('<b>ok</b>', $out);
    }

    public function test_foreach(): void
    {
        $engine = new BladeTemplateEngine();
        $out = $engine->render(
            '@foreach($items as $item)[{{ $item }}]@endforeach',
            ['items' => ['a', 'b', 'c']],
        );

        self::assertSame('[a][b][c]', $out);
    }

    public function test_if_else_endif(): void
    {
        $engine = new BladeTemplateEngine();
        $template = '@if($flag)yes@else no@endif';

        self::assertSame('yes', $engine->render($template, ['flag' => true]));
        self::assertSame(' no', $engine->render($template, ['flag' => false]));
    }

    public function test_nested_foreach_if(): void
    {
        $engine = new BladeTemplateEngine();
        $template = <<<'BLADE'
@foreach($tables as $table)
#{{ $table['name'] }}
@foreach($table['rows'] as $row)
@if($row['v'] === null)-@else{{ $row['v'] }}@endif
@endforeach
@endforeach
BLADE;

        $out = $engine->render($template, [
            'tables' => [
                [
                    'name' => 't1',
                    'rows' => [['v' => 'x'], ['v' => null]],
                ],
            ],
        ]);

        self::assertStringContainsString('#t1', $out);
        self::assertStringContainsString('x', $out);
        self::assertStringContainsString('-', $out);
    }

    public function test_temp_file_cleaned_up_on_success(): void
    {
        $before = $this->tempHtmlFiles();
        (new BladeTemplateEngine())->render('ok {{ $x }}', ['x' => 1]);
        self::assertSame($before, $this->tempHtmlFiles());
    }

    public function test_temp_file_cleaned_up_on_error(): void
    {
        $before = $this->tempHtmlFiles();

        try {
            (new BladeTemplateEngine())->render('{!! throw new \\RuntimeException(\'boom\') !!}', []);
            self::fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected — template evaluation throws
        }

        self::assertSame($before, $this->tempHtmlFiles());
    }

    /** @return list<string> */
    private function tempHtmlFiles(): array
    {
        $matches = glob(sys_get_temp_dir() . '/sqlcraft_html_*');
        $matches = $matches === false ? [] : $matches;
        sort($matches);

        return $matches;
    }
}
