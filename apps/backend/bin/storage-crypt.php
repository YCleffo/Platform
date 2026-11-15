<?php

declare(strict_types=1);

/** Authenticated streaming encryption for backup artifacts. The key is supplied separately. */
const MAGIC = "PLATFORM-STORAGE-BACKUP\0\x01";
const CHUNK_BYTES = 65536;

function exactRead(int $length): string
{
    $result = '';
    while (strlen($result) < $length) {
        $chunk = fread(STDIN, $length - strlen($result));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Encrypted backup is incomplete.');
        }
        $result .= $chunk;
    }
    return $result;
}

function writeAll(string $bytes): void
{
    for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
        $written = fwrite(STDOUT, substr($bytes, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Backup output failed.');
        }
    }
}

try {
    $operation = $argv[1] ?? '';
    $key = isset($argv[2]) ? file_get_contents($argv[2]) : false;
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
        throw new RuntimeException('Supply a private 32-byte backup key file.');
    }
    if ($operation === 'encrypt') {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        writeAll(MAGIC . $header);
        while (!feof(STDIN)) {
            $bytes = fread(STDIN, CHUNK_BYTES);
            if ($bytes === false) throw new RuntimeException('Backup input failed.');
            if ($bytes === '') continue;
            $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $bytes, MAGIC);
            writeAll(pack('N', strlen($encrypted)) . $encrypted);
        }
        $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', MAGIC,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        writeAll(pack('N', strlen($final)) . $final);
    } elseif ($operation === 'decrypt') {
        if (!hash_equals(MAGIC, exactRead(strlen(MAGIC)))) throw new RuntimeException('Unknown backup format.');
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
            exactRead(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
        while (true) {
            $length = unpack('Nlength', exactRead(4))['length'];
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                || $length > CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                throw new RuntimeException('Invalid encrypted backup record.');
            }
            $record = sodium_crypto_secretstream_xchacha20poly1305_pull($state, exactRead($length), MAGIC);
            if ($record === false) throw new RuntimeException('Backup authentication failed.');
            [$bytes, $tag] = $record;
            writeAll($bytes);
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if (fread(STDIN, 1) !== '') throw new RuntimeException('Unexpected data after backup end.');
                break;
            }
        }
    } else {
        throw new RuntimeException('Choose encrypt or decrypt.');
    }
    sodium_memzero($key);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
