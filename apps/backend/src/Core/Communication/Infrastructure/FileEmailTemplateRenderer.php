<?php

declare(strict_types=1);

namespace App\Core\Communication\Infrastructure;

use App\Core\Communication\Application\TemplateRendererInterface;
use App\Core\Communication\Application\TemplateRenderingException;
use App\Core\Communication\Domain\EmailTemplate;
use App\Core\Internationalization\Application\SupportedLocales;

final readonly class FileEmailTemplateRenderer implements TemplateRendererInterface
{
    public function __construct(
        private string $templateDirectory,
        private SupportedLocales $supportedLocales,
    ) {}

    public function render(
        string $templateCode,
        string $locale,
        array $variables,
    ): EmailTemplate {
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $templateCode) !== 1) {
            throw new TemplateRenderingException('Email template code is invalid.');
        }
        $resolvedLocale = $this->supportedLocales->resolve($locale);
        $canonicalLocale = $resolvedLocale->tag;
        if (!$this->hasTemplate($templateCode, $canonicalLocale)) {
            throw new TemplateRenderingException(sprintf(
                'Email template "%s" was not found for required locale "%s".',
                $templateCode,
                $canonicalLocale,
            ));
        }

        $subjectSource = $this->read($templateCode, $canonicalLocale, 'subject.txt');
        $htmlSource = $this->read($templateCode, $canonicalLocale, 'html');
        $textSource = $this->read($templateCode, $canonicalLocale, 'txt');
        $required = array_values(array_unique(array_merge(
            $this->placeholders($subjectSource),
            $this->placeholders($htmlSource),
            $this->placeholders($textSource),
        )));

        foreach ($required as $variable) {
            if (!array_key_exists($variable, $variables)) {
                throw new TemplateRenderingException(sprintf(
                    'Required email template variable "%s" is missing.',
                    $variable,
                ));
            }
        }
        $this->validateVariables($variables);

        return new EmailTemplate(
            code: $templateCode,
            locale: $canonicalLocale,
            subject: trim($this->renderBody($subjectSource, $variables, false)),
            htmlBody: $this->withLayout(
                $canonicalLocale,
                $this->renderBody($htmlSource, $variables, true),
            ),
            textBody: $this->renderBody($textSource, $variables, false),
            variables: $required,
        );
    }

    private function withLayout(string $locale, string $html): string
    {
        $layoutPath = rtrim($this->templateDirectory, '/\\')
            . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . '_layout.html';
        if (!is_file($layoutPath)) {
            return $html;
        }
        $layout = file_get_contents($layoutPath);
        if ($layout === false) {
            throw new TemplateRenderingException('Email layout cannot be read.');
        }

        $title = 'Platform';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
            $candidate = trim(strip_tags($matches[1]));
            if ($candidate !== '') {
                $title = $candidate;
            }
        }
        $content = $html;
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches) === 1) {
            $content = $matches[1];
        }
        $content = $this->inlineStyles(trim($content));
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return strtr($layout, [
            '{{ title }}' => $escape($title),
            '{{ preheader }}' => $escape($title),
            '{{ content }}' => $content,
            '{{ locale }}' => $escape($locale),
            '{{ year }}' => date('Y'),
        ]);
    }

    private function inlineStyles(string $content): string
    {
        $content = preg_replace_callback(
            '/<p\b([^>]*)>/i',
            static fn(array $match): string => str_contains($match[1], 'style=')
                ? $match[0]
                : '<p' . $match[1] . ' style="margin:0 0 16px;color:#374151;font:400 15px/1.65 Arial,Helvetica,sans-serif;">',
            $content,
        ) ?? $content;
        $content = preg_replace_callback(
            '/<a\b([^>]*)>/i',
            static fn(array $match): string => str_contains($match[1], 'style=')
                ? $match[0]
                : '<a' . $match[1] . ' style="display:inline-block;padding:12px 20px;border-radius:9px;background:#2563eb;color:#ffffff;font:700 15px/1 Arial,Helvetica,sans-serif;text-decoration:none;">',
            $content,
        ) ?? $content;

        return $content;
    }

    private function hasTemplate(string $code, string $locale): bool
    {
        foreach (['subject.txt', 'html', 'txt'] as $extension) {
            if (!is_file($this->path($code, $locale, $extension))) {
                return false;
            }
        }

        return true;
    }

    private function read(string $code, string $locale, string $extension): string
    {
        $content = file_get_contents($this->path($code, $locale, $extension));
        if ($content === false) {
            throw new TemplateRenderingException('Email template file cannot be read.');
        }

        return $content;
    }

    private function path(string $code, string $locale, string $extension): string
    {
        return rtrim($this->templateDirectory, '/\\')
            . DIRECTORY_SEPARATOR . $locale
            . DIRECTORY_SEPARATOR . $code . '.' . $extension;
    }

    /** @return list<string> */
    private function placeholders(string $source): array
    {
        preg_match_all('/{{\s*([A-Za-z][A-Za-z0-9]*)\s*}}/', $source, $matches);
        $placeholders = $matches[1] ?? [];

        /** @var list<string> $placeholders */
        return $placeholders;
    }

    /** @param array<string, mixed> $variables */
    private function validateVariables(array $variables): void
    {
        foreach ($variables as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new TemplateRenderingException('Email template variables must be scalar.');
            }
        }
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    private function renderBody(string $source, array $variables, bool $escapeHtml): string
    {
        $rendered = preg_replace_callback(
            '/{{\s*([A-Za-z][A-Za-z0-9]*)\s*}}/',
            static function (array $match) use ($variables, $escapeHtml): string {
                $value = (string) ($variables[$match[1]] ?? '');
                return $escapeHtml
                    ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : $value;
            },
            $source,
        );
        if ($rendered === null || str_contains($rendered, '{{') || str_contains($rendered, '}}')) {
            throw new TemplateRenderingException('Email template contains an invalid placeholder.');
        }

        return $rendered;
    }
}
