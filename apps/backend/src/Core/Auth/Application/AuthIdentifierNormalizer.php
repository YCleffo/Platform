<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;
use Normalizer;

final readonly class AuthIdentifierNormalizer
{
    public function normalize(string $type, string $value): string
    {
        return match ($type) {
            'email' => $this->email($value),
            'username' => $this->username($value),
            'phone' => $this->phone($value),
            default => throw new InvalidArgumentException('Unsupported authentication identifier type.'),
        };
    }

    private function email(string $value): string
    {
        $trimmed = trim($value);
        if (substr_count($trimmed, '@') !== 1) {
            throw new InvalidArgumentException('Email identifier is invalid.');
        }

        $parts = explode('@', $trimmed, 2);
        $local = $parts[0];
        $domain = $parts[1] ?? '';
        if ($local === '' || $domain === '' || preg_match('/[\x00-\x20\x7F]/u', $local) === 1) {
            throw new InvalidArgumentException('Email identifier is invalid.');
        }

        $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($asciiDomain === false || filter_var('x@' . $asciiDomain, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email identifier is invalid.');
        }

        $normalized = mb_strtolower($local) . '@' . strtolower($asciiDomain);
        if (mb_strlen($normalized) > 320) {
            throw new InvalidArgumentException('Email identifier is too long.');
        }

        return $normalized;
    }

    private function username(string $value): string
    {
        $normalized = Normalizer::normalize(trim($value), Normalizer::FORM_KC);
        if (!is_string($normalized) || $normalized === '') {
            throw new InvalidArgumentException('Username identifier is invalid.');
        }
        $normalized = mb_strtolower($normalized);
        if (mb_strlen($normalized) > 320 || preg_match('/[\x00-\x1F\x7F]/u', $normalized) === 1) {
            throw new InvalidArgumentException('Username identifier is invalid.');
        }

        return $normalized;
    }

    private function phone(string $value): string
    {
        $normalized = trim($value);
        if (preg_match('/\A\+[1-9][0-9]{7,14}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Phone identifier must be canonical E.164.');
        }

        return $normalized;
    }
}
