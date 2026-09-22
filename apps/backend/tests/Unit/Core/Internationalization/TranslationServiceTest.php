<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Internationalization;

use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Application\TranslationService;
use App\Core\Internationalization\Domain\Locale;
use App\Environment;
use Codeception\Test\Unit;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;

final class TranslationServiceTest extends Unit
{
    public function testTranslatesUserFacingMessageWithoutChangingIdentifier(): void
    {
        $service = $this->service();

        assertSame(
            'Примерное местоположение по IP',
            $service->translate(
                'session.approximate_location',
                new LocaleContext(new Locale('ru-RU'), 'default'),
            ),
        );
        assertSame(
            'Approximate location based on IP',
            $service->translate(
                'session.approximate_location',
                new LocaleContext(new Locale('en-US'), 'account'),
            ),
        );
    }

    public function testFormatsParametersForEachLocale(): void
    {
        $service = $this->service();

        assertSame(
            'Значение должно содержать не менее 12 символов.',
            $service->translate(
                'validation.length.min',
                new LocaleContext(new Locale('ru-RU'), 'accept-language'),
                ['min' => 12],
            ),
        );
        assertSame(
            'The value must contain at least 12 characters.',
            $service->translate(
                'validation.length.min',
                new LocaleContext(new Locale('en-US'), 'accept-language'),
                ['min' => 12],
            ),
        );
    }

    private function service(): TranslationService
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 4),
            environment: Environment::appEnv(),
        );

        return new TranslationService($runner->getContainer()->get(TranslatorInterface::class));
    }
}
