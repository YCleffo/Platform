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
        if (preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)*\z/', $templateCode) !== 1) {
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
            htmlBody: $this->renderBody($htmlSource, $variables, true),
            textBody: $this->renderBody($textSource, $variables, false),
            variables: $required,
        );
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
