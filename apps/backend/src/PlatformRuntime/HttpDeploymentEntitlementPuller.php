<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DomainException;

final readonly class HttpDeploymentEntitlementPuller implements DeploymentEntitlementPuller
{
    public function pull(string $controlPlaneUrl, string $authorization): array
    {
        $parts = parse_url($controlPlaneUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new DomainException('Invalid Control Plane URL.');
        }
        $url = rtrim($controlPlaneUrl, '/') . '/api/m2m/entitlements/latest';
        $handle = curl_init($url);
        if ($handle === false) { throw new DomainException('Entitlement transport unavailable.'); }
        $response = '';
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => ['Authorization: ' . $authorization, 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 1500000) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new DomainException('Control Plane entitlement pull failed.');
            }
            $decoded = json_decode($response, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) { throw new DomainException('Invalid entitlement response.'); }
            return $decoded;
        } catch (\JsonException) {
            throw new DomainException('Invalid entitlement response.');
        } finally {
            curl_close($handle);
        }
    }
}
