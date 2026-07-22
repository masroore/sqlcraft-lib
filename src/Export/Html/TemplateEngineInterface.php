<?php

declare(strict_types=1);

namespace SQLCraft\Export\Html;

interface TemplateEngineInterface
{
    /**
     * Render a template string with the given data.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data): string;
}
