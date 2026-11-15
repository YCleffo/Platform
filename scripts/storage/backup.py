"""Encrypted PostgreSQL/S3 snapshots and restore to a new database.

The encryption key stays outside the archive. Run against an explicitly named
backend and PostgreSQL container. Restore refuses an existing database.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile
from typing import BinaryIO

ROOT = Path(__file__).resolve().parents[2]
CRYPT = ROOT / 'apps/backend/bin/storage-crypt.php'
BRIDGE = '/app/bin/storage-backup-bridge.php'


def digest_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        while chunk := stream.read(65536):
            digest.update(chunk)
    return digest.hexdigest()


def encrypt(source: BinaryIO, target: Path, key: Path) -> dict[str, int | str]:
    digest = hashlib.sha256()
    size = 0
    partial = target.with_suffix(target.suffix + '.partial')
    with partial.open('xb') as output:
        process = subprocess.Popen(['php', str(CRYPT), 'encrypt', str(key)], stdin=subprocess.PIPE, stdout=output)
        assert process.stdin
        try:
            while chunk := source.read(65536):
                digest.update(chunk)
                size += len(chunk)
                process.stdin.write(chunk)
            process.stdin.close()
            if process.wait() != 0:
                raise RuntimeError('Backup encryption failed')
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
    partial.replace(target)
    return {'size': size, 'sha256': digest.hexdigest(), 'encryptedSha256': digest_file(target)}


def bridge(backend: str, mode: str, *arguments: str, **options):
    return subprocess.Popen(['docker', 'exec', '-i', backend, 'php', BRIDGE, mode, *arguments], **options)


def encrypt_command(command: list[str], target: Path, key: Path) -> dict[str, int | str]:
    process = subprocess.Popen(command, stdout=subprocess.PIPE)
    assert process.stdout
    try:
        metadata = encrypt(process.stdout, target, key)
        if process.wait() != 0:
            raise RuntimeError('Backup source failed')
        return metadata
    finally:
        process.stdout.close()
        if process.poll() is None:
            process.kill()
            process.wait()


def backup(args) -> None:
    destination = args.directory.resolve()
    destination.mkdir(parents=True, exist_ok=False)
    (destination / 'objects').mkdir()
    snapshot = bridge(args.backend, 'snapshot', stdin=subprocess.PIPE, stdout=subprocess.PIPE)
    assert snapshot.stdin and snapshot.stdout
    completed = False
    try:
        descriptor = json.loads(snapshot.stdout.readline())
        if descriptor['database'] != args.database or not re.fullmatch(r'[A-Fa-f0-9-]+', descriptor['snapshot']):
            raise RuntimeError('The requested database does not match the backend snapshot')
        count = 0
        with tempfile.TemporaryFile() as inventory:
            while line := snapshot.stdout.readline():
                item = json.loads(line)
                if item.get('inventoryComplete'):
                    completed = True
                    break
                relative = f"objects/{item['kind']}-{item['id']}.enc"
                process = bridge(args.backend, 'read', item['kind'], item['id'], stdout=subprocess.PIPE)
                assert process.stdout
                try:
                    metadata = encrypt(process.stdout, destination / relative, args.key_file)
                    if process.wait() != 0 or metadata['size'] != item['size'] or metadata['sha256'] != item['sha256']:
                        raise RuntimeError('An object is missing or differs from committed PostgreSQL metadata')
                finally:
                    process.stdout.close()
                    if process.poll() is None:
                        process.kill()
                        process.wait()
                inventory.write((json.dumps({**item, 'file': relative, **metadata}) + '\n').encode())
                count += 1
            if not completed:
                raise RuntimeError('Object inventory was interrupted')
            database = encrypt_command(['docker', 'exec', args.postgres, 'pg_dump', '-U', args.user,
                '-d', args.database, '-Fc', '--snapshot', descriptor['snapshot']], destination / 'database.dump.enc', args.key_file)
            inventory.seek(0)
            inventory_metadata = encrypt(inventory, destination / 'inventory.jsonl.enc', args.key_file)
        manifest = {'format': 1, 'database': args.database, 'objects': count,
            'createdAt': dt.datetime.now(dt.timezone.utc).isoformat(),
            'retainUntil': (dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=args.retention_days)).isoformat(),
            'dump': {'file': 'database.dump.enc', **database},
            'inventory': {'file': 'inventory.jsonl.enc', **inventory_metadata}}
        with tempfile.TemporaryFile() as data:
            data.write(json.dumps(manifest).encode()); data.seek(0)
            encrypt(data, destination / 'manifest.json.enc', args.key_file)
        snapshot.stdin.write(b'release\n'); snapshot.stdin.close()
        if snapshot.wait() != 0:
            raise RuntimeError('Backup snapshot release failed')
        (destination / 'READY').write_text(digest_file(destination / 'manifest.json.enc') + '\n', encoding='ascii')
        print(f'Encrypted backup verified: {count} objects and one consistent PostgreSQL snapshot.')
    finally:
        snapshot.stdout.close()
        if snapshot.poll() is None:
            snapshot.stdin.close()
            snapshot.wait(timeout=15)


def artifact(directory: Path, relative: str) -> Path:
    candidate = (directory / relative).resolve()
    if not candidate.is_relative_to(directory.resolve()) or not candidate.is_file():
        raise RuntimeError('Invalid backup artifact path')
    return candidate


def decrypt(path: Path, key: Path):
    source = path.open('rb')
    process = subprocess.Popen(['php', str(CRYPT), 'decrypt', str(key)], stdin=source, stdout=subprocess.PIPE)
    source.close()
    return process


def restore(args) -> None:
    configuration = bridge(args.backend, 'config', stdout=subprocess.PIPE)
    configured, _ = configuration.communicate(timeout=15)
    if configuration.returncode != 0 or json.loads(configured).get('database') != args.database:
        raise RuntimeError('The restore backend must point to the explicitly named new database')
    directory = args.directory.resolve()
    if not (directory / 'READY').is_file():
        raise RuntimeError('Restore requires a completed backup')
    manifest_path = directory / 'manifest.json.enc'
    if (directory / 'READY').read_text(encoding='ascii').strip() != digest_file(manifest_path):
        raise RuntimeError('Backup manifest integrity mismatch')
    process = decrypt(manifest_path, args.key_file)
    payload, _ = process.communicate(timeout=30)
    if process.returncode != 0 or len(payload) > 1048576:
        raise RuntimeError('Cannot authenticate backup manifest')
    manifest = json.loads(payload)
    if manifest.get('format') != 1:
        raise RuntimeError('Unsupported backup format')
    for section in ['dump', 'inventory']:
        entry = manifest[section]
        if digest_file(artifact(directory, entry['file'])) != entry['encryptedSha256']:
            raise RuntimeError('Backup ciphertext integrity mismatch')
    if not re.fullmatch(r'[a-zA-Z_][a-zA-Z0-9_]{0,62}', args.database):
        raise RuntimeError('Invalid target database name')
    # createdb refuses an existing target. No DROP, TRUNCATE or overwrite option exists.
    subprocess.run(['docker', 'exec', args.postgres, 'createdb', '-U', args.user, args.database], check=True)
    dump = decrypt(artifact(directory, manifest['dump']['file']), args.key_file)
    assert dump.stdout
    database = subprocess.Popen(['docker', 'exec', '-i', args.postgres, 'pg_restore', '-U', args.user,
        '-d', args.database, '--no-owner', '--no-privileges', '--exit-on-error', '--single-transaction'], stdin=dump.stdout)
    dump.stdout.close()
    if database.wait() != 0 or dump.wait() != 0:
        raise RuntimeError('PostgreSQL restore failed')
    inventory = decrypt(artifact(directory, manifest['inventory']['file']), args.key_file)
    assert inventory.stdout
    count = 0
    try:
        for line in inventory.stdout:
            item = json.loads(line)
            path = artifact(directory, item['file'])
            if digest_file(path) != item['encryptedSha256']:
                raise RuntimeError('Object backup ciphertext integrity mismatch')
            plaintext = decrypt(path, args.key_file)
            assert plaintext.stdout
            writer = bridge(args.backend, 'write', item['kind'], item['id'], str(item['size']), item['sha256'],
                stdin=plaintext.stdout, stdout=subprocess.PIPE)
            plaintext.stdout.close()
            result, _ = writer.communicate()
            receipt = json.loads(result) if writer.returncode == 0 else {}
            if writer.returncode != 0 or plaintext.wait() != 0 or receipt.get('verified') is not True or receipt.get('database') != args.database:
                raise RuntimeError('Restored object failed its full SHA-256 verification')
            count += 1
        if inventory.wait() != 0 or count != manifest['objects']:
            raise RuntimeError('Restored inventory is incomplete')
    finally:
        inventory.stdout.close()
        if inventory.poll() is None:
            inventory.kill(); inventory.wait()
    print(f'Restore verified: new database and {count} objects passed full SHA-256 checks.')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=['backup', 'restore'])
    parser.add_argument('--backend', required=True)
    parser.add_argument('--postgres', required=True)
    parser.add_argument('--database', required=True)
    parser.add_argument('--user', required=True)
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--key-file', type=Path, required=True)
    parser.add_argument('--retention-days', type=int, default=30)
    args = parser.parse_args()
    if not args.key_file.is_file() or args.key_file.stat().st_size != 32:
        parser.error('Supply a separate private 32-byte encryption key file')
    if not 1 <= args.retention_days <= 3650:
        parser.error('Retention must be between 1 and 3650 days')
    if args.operation == 'backup': backup(args)
    else: restore(args)


if __name__ == '__main__':
    main()
