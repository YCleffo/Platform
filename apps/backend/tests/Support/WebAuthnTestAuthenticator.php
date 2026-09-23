<?php

declare(strict_types=1);

namespace App\Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use RuntimeException;

use function base64_decode;
use function base64_encode;
use function chr;
use function hash;
use function json_encode;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function pack;
use function random_bytes;
use function rtrim;
use function str_pad;
use function str_repeat;
use function strlen;
use function strtr;

use const JSON_UNESCAPED_SLASHES;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const STR_PAD_LEFT;

final class WebAuthnTestAuthenticator
{
    public readonly OpenSSLAsymmetricKey $key;
    public readonly string $credentialId;
    public readonly string $coseKey;
    public readonly string $aaguid;

    public function __construct(
        ?OpenSSLAsymmetricKey $key = null,
        ?string $credentialId = null,
        string $aaguid = '00000000-0000-0000-0000-000000000000',
    ) {
        if ($key === null) {
            $res = openssl_pkey_new([
                'curve_name' => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
            ]);
            if ($res === false) {
                throw new RuntimeException('Failed to generate OpenSSL EC key.');
            }
            $this->key = $res;
        } else {
            $this->key = $key;
        }

        $this->credentialId = $credentialId ?? random_bytes(32);
        $this->aaguid = $aaguid;

        $details = openssl_pkey_get_details($this->key);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Failed to get EC key coordinates.');
        }

        $x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // COSE Key Map: 1 => 2 (EC2), 3 => -7 (ES256), -1 => 1 (P-256), -2 => x, -3 => y
        $map = MapObject::create([
            MapItem::create(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)),
            MapItem::create(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)),
            MapItem::create(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)),
            MapItem::create(NegativeIntegerObject::create(-2), ByteStringObject::create($x)),
            MapItem::create(NegativeIntegerObject::create(-3), ByteStringObject::create($y)),
        ]);
        $this->coseKey = (string) $map;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    public function getCredentialIdHex(): string
    {
        return bin2hex($this->credentialId);
    }

    public function getCredentialIdBase64Url(): string
    {
        return self::base64UrlEncode($this->credentialId);
    }

    /**
     * @param list<string> $transports
     */
    public function makeRegistrationResponse(
        string $challengeBase64Url,
        string $rpId = 'localhost',
        string $origin = 'http://localhost:5173',
        int $signCount = 0,
        bool $userPresent = true,
        bool $userVerified = true,
        bool $backupEligible = false,
        bool $backupState = false,
        ?string $aaguidBytes = null,
        array $transports = ['internal'],
        string $type = 'webauthn.create',
    ): string {
        $clientDataJson = (string) json_encode([
            'type' => $type,
            'challenge' => $challengeBase64Url,
            'origin' => $origin,
        ], JSON_UNESCAPED_SLASHES);

        $rpIdHash = hash('sha256', $rpId, true);

        $flags = 0x40; // AT (attested credential data present)
        if ($userPresent) {
            $flags |= 0x01; // UP
        }
        if ($userVerified) {
            $flags |= 0x04; // UV
        }
        if ($backupEligible) {
            $flags |= 0x08; // BE
        }
        if ($backupState) {
            $flags |= 0x10; // BS
        }

        $flagsByte = chr($flags);
        $counterBytes = pack('N', $signCount);
        $aaguid = $aaguidBytes ?? str_repeat("\x00", 16);
        $credIdLen = pack('n', strlen($this->credentialId));

        $authData = $rpIdHash . $flagsByte . $counterBytes . $aaguid . $credIdLen . $this->credentialId . $this->coseKey;

        $attestationObject = (string) MapObject::create([
            MapItem::create(TextStringObject::create('fmt'), TextStringObject::create('none')),
            MapItem::create(TextStringObject::create('attStmt'), MapObject::create([])),
            MapItem::create(TextStringObject::create('authData'), ByteStringObject::create($authData)),
        ]);

        return (string) json_encode([
            'id' => self::base64UrlEncode($this->credentialId),
            'rawId' => self::base64UrlEncode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64UrlEncode($clientDataJson),
                'attestationObject' => self::base64UrlEncode($attestationObject),
                'transports' => $transports,
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    public function makeAuthenticationResponse(
        string $challengeBase64Url,
        string $userHandle,
        string $rpId = 'localhost',
        string $origin = 'http://localhost:5173',
        int $signCount = 1,
        bool $userPresent = true,
        bool $userVerified = true,
        bool $backupEligible = false,
        bool $backupState = false,
        bool $tamperSig = false,
        string $type = 'webauthn.get',
        ?string $overrideCredentialId = null,
        ?string $overrideUserHandle = null,
        ?string $overrideOrigin = null,
    ): string {
        $clientOrigin = $overrideOrigin ?? $origin;
        $clientDataJson = (string) json_encode([
            'type' => $type,
            'challenge' => $challengeBase64Url,
            'origin' => $clientOrigin,
        ], JSON_UNESCAPED_SLASHES);

        $rpIdHash = hash('sha256', $rpId, true);

        $flags = 0x00;
        if ($userPresent) {
            $flags |= 0x01; // UP
        }
        if ($userVerified) {
            $flags |= 0x04; // UV
        }
        if ($backupEligible) {
            $flags |= 0x08; // BE
        }
        if ($backupState) {
            $flags |= 0x10; // BS
        }

        $flagsByte = chr($flags);
        $counterBytes = pack('N', $signCount);

        $authData = $rpIdHash . $flagsByte . $counterBytes;
        $dataToSign = $authData . hash('sha256', $clientDataJson, true);

        $signature = '';
        openssl_sign($dataToSign, $signature, $this->key, OPENSSL_ALGO_SHA256);
        if ($tamperSig) {
            $signature ^= str_repeat("\xff", strlen($signature));
        }

        $credId = $overrideCredentialId ?? $this->credentialId;
        $handle = $overrideUserHandle ?? $userHandle;

        return (string) json_encode([
            'id' => self::base64UrlEncode($credId),
            'rawId' => self::base64UrlEncode($credId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64UrlEncode($clientDataJson),
                'authenticatorData' => self::base64UrlEncode($authData),
                'signature' => self::base64UrlEncode($signature),
                'userHandle' => self::base64UrlEncode($handle),
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
