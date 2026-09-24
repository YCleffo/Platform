<?php

declare(strict_types=1);

namespace ControlPlane\Deployment\Domain;

final class DomainName
{
    public static function normalize(string $input): string
    {
        $input = rtrim(trim($input), '.');
        if ($input === '' || strpbrk($input, '/:@?#\\') !== false) {
            throw new \InvalidArgumentException('Invalid domain name.');
        }

        $ascii = idn_to_ascii($input, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false) {
            throw new \InvalidArgumentException('Invalid IDNA domain.');
        }
        $ascii = strtolower($ascii);

        if (strlen($ascii) > 253 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+\z/D', $ascii) !== 1) {
            throw new \InvalidArgumentException('Invalid domain label.');
        }
        foreach (explode('.', $ascii) as $label) {
            if (strlen($label) > 63 || in_array($label, ['localhost', 'local', 'internal', 'invalid', 'test'], true)) {
                throw new \InvalidArgumentException('Internal domain names are not accepted.');
            }
        }
        if (filter_var($ascii, FILTER_VALIDATE_IP) !== false) {
            throw new \InvalidArgumentException('IP literals are not accepted.');
        }

        return $ascii;
    }
}
