<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\HtmlExportOptions;
use SQLCraft\Export\HtmlFormatWriter;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use Twig\Environment;

final class HtmlFormatWriterTest extends TestCase
{
    public function test_default_template_renders_valid_html(): void
    {
        $html = $this->export([
            'users' => [
                'columns' => [$this->column('id', 'INTEGER'), $this->column('name', 'TEXT')],
                'rows' => [['id' => 1, 'name' => 'Ada']],
            ],
        ]);

        if ($html === '') {
            throw new RuntimeException('HTML export was empty.');
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html);
        $errors = array_values(array_filter(
            libxml_get_errors(),
            // libxml HTML parser is HTML4; ignore HTML5 element "unknown tag" warnings (code 801).
            static fn (\LibXMLError $error): bool => $error->code !== 801,
        ));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded);
        self::assertSame([], $errors);
        self::assertStringContainsString('<section>', $html);
    }

    public function test_title_appears_in_output(): void
    {
        $html = $this->export(
            [
                'users' => [
                    'columns' => [$this->column('id', 'INTEGER')],
                    'rows' => [['id' => 1]],
                ],
            ],
            new HtmlExportOptions(title: 'My Export'),
        );

        self::assertStringContainsString('My Export', $html);
        self::assertStringContainsString('<title>My Export</title>', $html);
    }

    public function test_tables_and_rows_present(): void
    {
        $html = $this->export([
            'users' => [
                'columns' => [$this->column('name', 'TEXT')],
                'rows' => [['name' => 'Ada']],
            ],
            'orders' => [
                'columns' => [$this->column('total', 'INTEGER')],
                'rows' => [['total' => 42]],
            ],
        ]);

        self::assertStringContainsString('<h2>users</h2>', $html);
        self::assertStringContainsString('<h2>orders</h2>', $html);
        self::assertStringContainsString('<td>Ada</td>', $html);
        self::assertStringContainsString('<td>42</td>', $html);
    }

    public function test_null_renders_as_dash(): void
    {
        $html = $this->export([
            'users' => [
                'columns' => [$this->column('name', 'TEXT')],
                'rows' => [['name' => null]],
            ],
        ]);

        self::assertStringContainsString('class="null">&mdash;</td>', $html);
    }

    public function test_custom_template_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sqlcraft_html_tpl_');
        self::assertNotFalse($path);
        file_put_contents($path, '<html><body>path:{{ $title }}</body></html>');

        try {
            $html = $this->export(
                [
                    'users' => [
                        'columns' => [$this->column('id', 'INTEGER')],
                        'rows' => [],
                    ],
                ],
                new HtmlExportOptions(templatePath: $path, title: 'FromPath'),
            );
            self::assertSame('<html><body>path:FromPath</body></html>', $html);
        } finally {
            @unlink($path);
        }
    }

    public function test_custom_template_string(): void
    {
        $html = $this->export(
            [
                'users' => [
                    'columns' => [$this->column('id', 'INTEGER')],
                    'rows' => [],
                ],
            ],
            new HtmlExportOptions(templateString: '<p>{{ $title }}</p>', title: 'Inline'),
        );

        self::assertSame('<p>Inline</p>', $html);
    }

    public function test_use_twig_throws_when_not_installed(): void
    {
        if (class_exists(Environment::class)) {
            self::markTestSkipped('twig/twig is installed in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('twig/twig is not installed');

        $this->export(
            [
                'users' => [
                    'columns' => [$this->column('id', 'INTEGER')],
                    'rows' => [],
                ],
            ],
            new HtmlExportOptions(useTwig: true),
        );
    }

    /**
     * @param  array<string, array{columns: list<ColumnMeta>, rows: list<array<string, mixed>>}>  $tables
     */
    private function export(array $tables, ?HtmlExportOptions $htmlOptions = null): string
    {
        $sink = new StringBufferSink;
        $writer = new HtmlFormatWriter;
        $options = new DumpOptions(
            format: 'html',
            scope: DumpScope::database('shop'),
            htmlOptions: $htmlOptions,
        );

        $writer->writeHeader($sink, $options);
        foreach ($tables as $name => $spec) {
            $table = new TableStatus($name);
            $writer->writeTableHeader($sink, $table, $options);
            if ($spec['rows'] !== [] || $spec['columns'] !== []) {
                $writer->writeRows($sink, $table, $spec['rows'], $spec['columns'], $options);
            }
            $writer->writeTableFooter($sink, $table);
        }
        $writer->writeFooter($sink, $options);

        return $sink->contents();
    }

    private function column(string $name, string $type): ColumnMeta
    {
        return new ColumnMeta(
            name: $name,
            dataType: new DataType($type),
            nullable: true,
            autoIncrement: false,
            primary: false,
            generated: false,
            default: DefaultValue::nullValue(),
            collation: null,
            comment: null,
            onUpdate: null,
            privileges: [],
            origName: null,
            defaultConstraintName: null,
        );
    }
}
