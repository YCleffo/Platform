<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Internationalization;

use App\Core\Internationalization\Application\LocaleNormalizer;
use Codeception\Test\Unit;
use InvalidArgumentException;

use function PHPUnit\Framework\assertSame;

final class LocaleNormalizerTest extends Unit
{
    public function testCanonicalizesLegacyAndBcp47Forms(): void
    {
        $normalizer = new LocaleNormalizer();

        assertSame('ru-RU', $normalizer->normalize('ru')->tag);
        assertSame('ru-RU', $normalizer->normalize('ru_RU')->tag);
        assertSame('ru-RU', $normalizer->normalize('ru-RU')->tag);
        assertSame('en-US', $normalizer->normalize('EN_us')->tag);
    }

    public function testRejectsInvalidLocale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LocaleNormalizer())->normalize('../ru');
    }
}
