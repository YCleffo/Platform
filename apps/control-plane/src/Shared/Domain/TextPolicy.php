<?php

declare(strict_types=1);

namespace ControlPlane\Shared\Domain;

use Normalizer;

final class TextPolicy
{
    public static function label(string $value, int $maxLength): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException('Invalid label.');
        }
        $value = trim($value);
        $value = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($value === false || $value === '' || mb_strlen($value, 'UTF-8') > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException('Invalid label.');
        }

        return $value;
    }
}
