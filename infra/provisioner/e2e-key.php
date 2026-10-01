<?php

declare(strict_types=1);

$dir = dirname(__DIR__, 2) . '/runtime/provisioning-secrets';
if (!is_dir($dir)) { mkdir($dir, 0700, true); }
$pair = sodium_crypto_sign_keypair();
file_put_contents($dir . '/e2e-signing.key', base64_encode(sodium_crypto_sign_secretkey($pair)));
file_put_contents($dir . '/e2e-public.hex', bin2hex(sodium_crypto_sign_publickey($pair)));
