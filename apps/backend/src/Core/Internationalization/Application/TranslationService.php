<?php

declare(strict_types=1);

namespace App\Core\Internationalization\Application;

use App\Environment;
use Yiisoft\Translator\TranslatorInterface;

final readonly class TranslationService
{
    public function __construct(private TranslatorInterface $translator) {}

    /** @param array<array-key, mixed> $parameters */
    public function translate(
        string $messageId,
        LocaleContext $context,
        array $parameters = [],
        string $category = 'app',
    ): string {
        $translated = $this->translator->translate(
            $messageId,
            $parameters,
            $category,
            $context->locale->tag,
        );

        if ($translated !== $messageId || !Environment::isProd()) {
            return $translated;
        }

        $fallback = $this->translator->translate(
            'http.internal_error',
            [],
            $category,
            $context->locale->tag,
        );

        return $fallback === 'http.internal_error' ? 'An error occurred.' : $fallback;
    }
}
