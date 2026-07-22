<?php

declare(strict_types=1);

namespace SQLCraft\Export\Html;

use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TwigTemplateEngine implements TemplateEngineInterface
{
    #[\Override]
    public function render(string $template, array $data): string
    {
        $loader = new ArrayLoader(['template' => $template]);
        $twig = new Environment($loader, ['autoescape' => 'html']);

        return $twig->render('template', $data);
    }
}
