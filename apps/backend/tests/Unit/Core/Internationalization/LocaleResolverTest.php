<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Internationalization;

use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\LocaleResolver;
use App\Core\Internationalization\Application\ResolvedLocale;
use App\Core\Internationalization\Application\SupportedLocales;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

final class LocaleResolverTest extends Unit
{
    public function testUsesPriorityAndCanonicalization(): void
    {
        $resolver = $this->resolver();

        $explicit = $resolver->resolve('en', 'ru-RU', 'ru;q=1');
        $savedPreference = $resolver->resolve(null, 'en_US', 'ru;q=1');
        $account = $resolver->resolve(null, 'en_US', null);

        assertSame('en-US', $explicit->locale->tag);
        assertSame(ResolvedLocale::EXPLICIT, $explicit->source);
        assertSame('en-US', $savedPreference->locale->tag);
        assertSame(ResolvedLocale::ACCOUNT, $savedPreference->source);
        assertSame('en-US', $account->locale->tag);
        assertSame(ResolvedLocale::ACCOUNT, $account->source);
    }

    public function testNegotiatesQualityLanguageFallbackAndOrder(): void
    {
        $resolved = $this->resolver()->resolve(
            null,
            null,
            'fr-FR,ru-RU;q=0.7,en-GB;q=0.9,en;q=0.8',
        );

        assertSame('en-US', $resolved->locale->tag);
        assertSame(ResolvedLocale::ACCEPT_LANGUAGE, $resolved->source);
    }

    public function testFallsBackForUnsupportedLanguages(): void
    {
        $resolved = $this->resolver()->resolve(null, null, 'de-DE,fr;q=0.8');

        assertSame('en-US', $resolved->locale->tag);
        assertSame(ResolvedLocale::ACCEPT_LANGUAGE, $resolved->source);
    }

    /** @dataProvider languageFamilies */
    public function testMapsLanguageFamilies(string $input, string $expected): void
    {
        assertSame($expected, $this->resolver()->resolve($input, null, null)->locale->tag);
    }

    /** @return iterable<string, array{string, string}> */
    public static function languageFamilies(): iterable
    {
        foreach ([
            'ru' => 'ru-RU',
            'ru-RU' => 'ru-RU',
            'ru-KZ' => 'ru-RU',
            'en' => 'en-US',
            'en-GB' => 'en-US',
            'de-DE' => 'en-US',
            'fr-FR' => 'en-US',
            'es-ES' => 'en-US',
            'zh-CN' => 'en-US',
        ] as $input => $expected) {
            yield $input => [$input, $expected];
        }
    }

    /** @dataProvider weightedHeaders */
    public function testUsesHighestQualityLanguageFamily(string $header, string $expected): void
    {
        assertSame($expected, $this->resolver()->resolve(null, null, $header)->locale->tag);
    }

    /** @return iterable<string, array{string, string}> */
    public static function weightedHeaders(): iterable
    {
        yield 'foreign preferred over Russian' => ['de-DE,de;q=0.9,ru;q=0.8', 'en-US'];
        yield 'Russian preferred' => ['ru;q=1,en;q=0.8', 'ru-RU'];
        yield 'English preferred' => ['en;q=1,ru;q=0.8', 'en-US'];
    }

    public function testIgnoresZeroAndMalformedQualityValues(): void
    {
        $resolved = $this->resolver()->resolve(null, null, 'en-US;q=broken,ru-RU;q=0,en;q=0.4');

        assertSame('en-US', $resolved->locale->tag);
        assertSame(ResolvedLocale::ACCEPT_LANGUAGE, $resolved->source);
    }

    public function testUsesDefaultWithoutAUsableHeaderOrAccountPreference(): void
    {
        $missing = $this->resolver()->resolve(null, null, null);
        $malformed = $this->resolver()->resolve(null, null, ';;;,en;q=invalid');

        assertSame('ru-RU', $missing->locale->tag);
        assertSame(ResolvedLocale::DEFAULT, $missing->source);
        assertSame('ru-RU', $malformed->locale->tag);
        assertSame(ResolvedLocale::DEFAULT, $malformed->source);
    }

    private function resolver(): LocaleResolver
    {
        return new LocaleResolver(new SupportedLocales(
            new LocaleNormalizer(),
            ['ru-RU', 'en-US'],
            'ru-RU',
        ));
    }
}
