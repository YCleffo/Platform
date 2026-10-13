<?php

declare(strict_types=1);

namespace ControlPlane\Entitlement\Application;

use Doctrine\DBAL\Connection;

final readonly class EntitlementSigner
{
    public function __construct(private Connection $connection, private SigningKeyResolver $resolver) {}

    /** @return array{keyId:string,signature:string} */
    public function sign(string $canonicalDocument): array
    {
        $key = $this->loadKey();
        return ['keyId' => $key['keyId'], 'signature' => sodium_crypto_sign_detached($canonicalDocument, $key['secret'])];
    }

    public function activeKeyId(): string
    {
        return $this->loadKey()['keyId'];
    }

    /** @return array{keyId:string,secret:non-empty-string} */
    private function loadKey(): array
    {
        $key = $this->connection->fetchAssociative(<<<'SQL'
SELECT key_id, encode(public_key, 'hex') AS public_key_hex, private_key_secret_ref
FROM commercial.signing_keys
WHERE purpose = 'entitlement' AND algorithm = 'Ed25519' AND status = 'active'
  AND revoked_at IS NULL AND valid_from <= now() AND (valid_until IS NULL OR valid_until > now())
ORDER BY valid_from DESC, key_id DESC LIMIT 1
SQL);
        if ($key === false) { throw new \DomainException('An active entitlement signing key is unavailable.'); }
        $secret = $this->resolver->resolve($key['private_key_secret_ref']);
        if ($secret === null || $secret === '' || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || !hash_equals($key['public_key_hex'], bin2hex(sodium_crypto_sign_publickey_from_secretkey($secret)))) {
            throw new \DomainException('Entitlement signing key could not be resolved.');
        }
        return ['keyId' => $key['key_id'], 'secret' => $secret];
    }
}
