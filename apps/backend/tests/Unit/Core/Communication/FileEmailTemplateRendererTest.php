<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Communication;

use App\Core\Communication\Application\TemplateRenderingException;
use App\Core\Communication\Infrastructure\FileEmailTemplateRenderer;
use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\SupportedLocales;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class FileEmailTemplateRendererTest extends Unit
{
    public function testRendersRussianAndEnglishTemplates(): void
    {
        $renderer = $this->renderer();

        $russian = $renderer->render('auth.password.reset', 'ru-RU', [
            'displayName' => 'Юрий',
            'actionUrl' => 'https://example.test/reset?a=1&b=2',
        ]);
        $english = $renderer->render('auth.account.created', 'en-US', [
            'displayName' => 'Yuran',
            'signInUrl' => 'https://example.test/sign-in',
        ]);

        assertSame('Сброс пароля', $russian->subject);
        assertSame('ru-RU', $russian->locale);
        assertStringContainsString('Юрий', $russian->textBody);
        assertStringContainsString('a=1&amp;b=2', $russian->htmlBody);
        assertSame('Your account has been created', $english->subject);
        assertSame('en-US', $english->locale);
        assertSame(['displayName', 'signInUrl'], $english->variables);
    }

    public function testRendersUnderscoreTemplateCodesWithBrandedLayout(): void
    {
        $template = $this->renderer()->render('auth.external_identity.linked', 'ru-RU', []);

        assertSame('ru-RU', $template->locale);
        assertStringContainsString('Platform', $template->htmlBody);
        assertStringContainsString('background:#f3f5f9', $template->htmlBody);
    }

    public function testNormalizesLegacyLocaleAndUsesEnglishForForeignLanguage(): void
    {
        $variables = [
            'displayName' => 'Yuran',
            'signInUrl' => 'https://example.test/sign-in',
        ];

        $legacy = $this->renderer()->render('auth.account.created', 'en', $variables);
        $fallback = $this->renderer()->render('auth.account.created', 'fr-FR', $variables);

        assertSame('en-US', $legacy->locale);
        assertSame('en-US', $fallback->locale);
    }

    public function testRejectsMissingVariable(): void
    {
        $this->expectException(TemplateRenderingException::class);
        $this->expectExceptionMessage('actionUrl');

        $this->renderer()->render('auth.password.reset', 'en', [
            'displayName' => 'Yuran',
        ]);
    }

    public function testRejectsNonScalarVariable(): void
    {
        $this->expectException(TemplateRenderingException::class);

        /** @psalm-suppress InvalidArgument */
        $this->renderer()->render('auth.account.created', 'en', [
            'displayName' => ['not' => 'scalar'],
            'signInUrl' => 'https://example.test/sign-in',
        ]);
    }

    private function renderer(): FileEmailTemplateRenderer
    {
        return new FileEmailTemplateRenderer(
            dirname(__DIR__, 4) . '/resources/email',
            new SupportedLocales(new LocaleNormalizer(), ['ru-RU', 'en-US'], 'ru-RU'),
        );
    }
}
