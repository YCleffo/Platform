<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

final readonly class HttpDeploymentStatusReporter implements DeploymentStatusReporter
{
    public function __construct(private RuntimeSecretResolver $secrets,
        private string $controlPlaneUrl, private string $credentialReference) {}

    public function ready(int $entitlementSequence): string
    {
        if ($entitlementSequence < 1) { throw new \DomainException('Invalid entitlement sequence.'); }
        $response = $this->send(['status' => 'ready', 'entitlementSequence' => $entitlementSequence]);
        if (!in_array($response['status'] ?? null, ['ready', 'already_ready'], true)) {
            throw new \DomainException('Deployment status response is invalid.');
        }
        return (string) $response['status'];
    }

    public function failed(string $machineErrorCode): void
    {
        if (!in_array($machineErrorCode, ['runtime.entitlement_sync_failed',
            'runtime.entitlement_rejected', 'runtime.bootstrap_failed'], true)) {
            throw new \DomainException('Invalid runtime failure code.');
        }
        $response = $this->send(['status' => 'failed', 'errorCode' => $machineErrorCode]);
        if (($response['status'] ?? null) !== 'failed') {
            throw new \DomainException('Deployment status response is invalid.');
        }
    }

    /** @return array<string,mixed> */
    private function send(array $body): array
    {
        $parts = parse_url($this->controlPlaneUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \DomainException('Invalid Control Plane URL.');
        }
        $secret = $this->secrets->deploymentCredential($this->credentialReference);
        $handle = curl_init(rtrim($this->controlPlaneUrl, '/') . '/api/m2m/deployment/status');
        if ($handle === false) { throw new \DomainException('Deployment status transport unavailable.'); }
        $response = '';
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Service ' . $secret, 'Content-Type: application/json',
                'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 4096) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \DomainException('Deployment status report failed.');
            }
            $decoded = json_decode($response, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) { throw new \DomainException('Deployment status response is invalid.'); }
            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (\JsonException) {
            throw new \DomainException('Deployment status response is invalid.');
        } finally {
            curl_close($handle);
        }
    }
}
