<?php

declare(strict_types=1);

namespace SQLCraft\Export\Html;

use RuntimeException;
use SQLCraft\Export\HtmlExportOptions;
use Twig\Environment as TwigEnvironment;

final class TemplateEngineFactory
{
    public static function create(HtmlExportOptions $options): TemplateEngineInterface
    {
        if ($options->useTwig) {
            if (! class_exists(TwigEnvironment::class)) {
                throw new RuntimeException(
                    'twig/twig is not installed. Run: composer require twig/twig',
                );
            }

            return new TwigTemplateEngine();
        }

        return new BladeTemplateEngine();
    }

    /**
     * Resolve the template string from options, falling back to the bundled default.
     */
    public static function resolveTemplate(HtmlExportOptions $options): string
    {
        if ($options->templatePath !== null) {
            if (! is_readable($options->templatePath)) {
                throw new RuntimeException(
                    sprintf('HTML template file not readable: %s', $options->templatePath),
                );
            }

            return (string) file_get_contents($options->templatePath);
        }

        if ($options->templateString !== null) {
            return $options->templateString;
        }

        return (string) file_get_contents(__DIR__ . '/default-template.html');
    }
}
