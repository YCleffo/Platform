<?php

declare(strict_types=1);

use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\SupportedLocales;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\Translator;
use Yiisoft\Translator\TranslatorInterface;

/** @var array<string, mixed> $params */
$internationalization = $params['internationalization'];

return [
    SupportedLocales::class => [
        '__construct()' => [
            'normalizer' => new LocaleNormalizer(),
            'localeTags' => $internationalization['supportedLocales'],
            'defaultLocale' => $internationalization['defaultLocale'],
        ],
    ],
    TranslatorInterface::class => static function () use ($internationalization): TranslatorInterface {
        $translator = new Translator(
            locale: $internationalization['defaultLocale'],
            fallbackLocale: $internationalization['defaultLocale'],
            defaultMessageFormatter: new IntlMessageFormatter(),
        );
        $translator->addCategorySources(new CategorySource(
            'app',
            new MessageSource($internationalization['messagesPath']),
            new IntlMessageFormatter(),
        ));

        return $translator;
    },
];
