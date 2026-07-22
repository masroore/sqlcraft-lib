<?php

declare(strict_types=1);

namespace SQLCraft\Export\Html;

use RuntimeException;
use Throwable;

/**
 * Minimal Blade-like compiler for HTML export templates.
 *
 * Supports: {{ }}, {!! !!}, @foreach, @endforeach, @if, @elseif, @else, @endif.
 */
final class BladeTemplateEngine implements TemplateEngineInterface
{
    #[\Override]
    public function render(string $template, array $data): string
    {
        $compiled = $this->compile($template);
        $tmp = tempnam(sys_get_temp_dir(), 'sqlcraft_html_');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create temporary file for HTML template rendering.');
        }

        file_put_contents($tmp, $compiled);

        try {
            return (static function (string $__path, array $__data): string {
                extract($__data, EXTR_SKIP);
                ob_start();
                try {
                    include $__path;
                } catch (Throwable $e) {
                    ob_end_clean();
                    throw $e;
                }

                return (string) ob_get_clean();
            })($tmp, $data);
        } finally {
            @unlink($tmp);
        }
    }

    private function compile(string $template): string
    {
        // Raw output first so {!! !!} is not swallowed by {{ }} patterns.
        $template = preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?= $1 ?>', $template) ?? $template;

        $template = preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/s',
            '<?= htmlspecialchars((string) ($1), ENT_QUOTES, \'UTF-8\') ?>',
            $template,
        ) ?? $template;

        $template = $this->replaceDirective($template, '@foreach', '<?php foreach (%s): ?>');
        $template = str_replace('@endforeach', '<?php endforeach; ?>', $template);
        $template = $this->replaceDirective($template, '@elseif', '<?php elseif (%s): ?>');
        $template = $this->replaceDirective($template, '@if', '<?php if (%s): ?>');
        $template = str_replace('@else', '<?php else: ?>', $template);
        $template = str_replace('@endif', '<?php endif; ?>', $template);

        return $template;
    }

    /**
     * Replace @directive(expr) while respecting nested parentheses in expr.
     */
    private function replaceDirective(string $template, string $directive, string $format): string
    {
        $needle = $directive.'(';
        $result = '';
        $offset = 0;
        $length = strlen($template);

        while (($pos = strpos($template, $needle, $offset)) !== false) {
            $result .= substr($template, $offset, $pos - $offset);
            $start = $pos + strlen($needle);
            $depth = 1;
            $i = $start;

            while ($i < $length && $depth > 0) {
                $char = $template[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
                $i++;
            }

            if ($depth !== 0) {
                // Unbalanced — leave remainder unchanged.
                $result .= substr($template, $pos);
                return $result;
            }

            $expression = substr($template, $start, $i - $start - 1);
            $result .= sprintf($format, $expression);
            $offset = $i;
        }

        return $result.substr($template, $offset);
    }
}
