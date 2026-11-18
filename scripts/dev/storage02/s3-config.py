"""Generate separate local S3 application/initializer credentials; never print secrets or replace them on rerun."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import secrets
from urllib.parse import urlparse


def write_private(path: Path, content: str) -> None:
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w', encoding='utf-8', newline='\n') as stream:
        stream.write(content)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, default=Path('runtime/s3'))
    test = parser.add_mutually_exclusive_group()
    test.add_argument('--test', action='store_true')
    test.add_argument('--test-suite', choices=['education-local', 'ci'])
    parser.add_argument('--port', type=int, default=8333)
    parser.add_argument('--public-endpoint')
    parser.add_argument('--cors-origin', action='append', help='Explicit browser origin (repeatable)')
    args = parser.parse_args()
    if not 1024 <= args.port <= 65535:
        raise SystemExit('Choose an unprivileged S3 port.')
    directory = args.directory.resolve()
    directory.mkdir(parents=True, exist_ok=True)
    config = directory / 'seaweedfs.json'
    env = directory / 's3.env'
    if config.exists() or env.exists():
        if not config.exists() or not env.exists():
            raise SystemExit('Incomplete existing S3 credentials; preserve both files and repair explicitly.')
        print('Existing S3 credentials preserved; no files changed.')
        return
    endpoint = args.public_endpoint or f'http://localhost:{args.port}'
    url = urlparse(endpoint)
    if url.scheme not in ('http', 'https') or not url.hostname or url.username or url.password or url.query or url.fragment:
        raise SystemExit('Provide a plain HTTPS endpoint, or loopback HTTP for development.')
    if url.scheme == 'http' and url.hostname not in ('localhost', '127.0.0.1', '::1'):
        raise SystemExit('External endpoints require HTTPS.')
    prefix = f'{args.test_suite}-test' if args.test_suite else ('storage02-test' if args.test else 'platform')
    cors_origins = args.cors_origin or ['http://localhost:5174', 'http://127.0.0.1:5174', 'http://localhost:5173']
    for origin in cors_origins:
        parsed = urlparse(origin)
        try:
            port = parsed.port
        except ValueError:
            raise SystemExit('Invalid S3 browser origin port.') from None
        if (parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username is not None
                or parsed.password is not None or parsed.path or parsed.query or parsed.fragment
                or '*' in origin or ',' in origin or any(char.isspace() for char in origin)
                or (port is not None and not 1 <= port <= 65535)):
            raise SystemExit('Provide explicit HTTP(S) origins without paths, credentials or wildcards.')
    buckets = [prefix + '-storage', prefix + '-education']
    app_key = 'app-' + secrets.token_hex(12)
    app_secret = secrets.token_urlsafe(36)
    init_key = 'init-' + secrets.token_hex(12)
    init_secret = secrets.token_urlsafe(36)
    identities = [
        {'name': prefix + '-app', 'credentials': [{'accessKey': app_key, 'secretKey': app_secret}],
         'actions': [action + ':' + bucket for bucket in buckets for action in ('Read', 'Write', 'List', 'Tagging')]},
        {'name': prefix + '-initializer', 'credentials': [{'accessKey': init_key, 'secretKey': init_secret}],
         'actions': ['Admin', 'Read', 'Write', 'List', 'Tagging']},
    ]
    write_private(config, json.dumps({'identities': identities}, indent=2) + '\n')
    values = {
        'S3_CONFIG_FILE': config.as_posix(),
        'S3_PORT': str(args.port), 'S3_ENDPOINT_INTERNAL': 'http://seaweedfs:8333',
        'S3_PUBLIC_ENDPOINT': endpoint, 'S3_REGION': 'us-east-1',
        'S3_BUCKET_STORAGE': buckets[0], 'S3_BUCKET_EDUCATION': buckets[1],
        'S3_ACCESS_KEY_ID': app_key, 'S3_SECRET_ACCESS_KEY': app_secret,
        'S3_INIT_ACCESS_KEY_ID': init_key, 'S3_INIT_SECRET_ACCESS_KEY': init_secret,
        'S3_PATH_STYLE': 'true', 'S3_PRESIGN_TTL_SECONDS': '120', 'S3_MULTIPART_PART_SIZE': '8388608',
        'S3_UPLOAD_MAX_BYTES': '10737418240', 'S3_TRANSFER_CONCURRENCY': '3',
        'S3_CORS_ORIGINS': ','.join(cors_origins),
    }
    write_private(env, ''.join(f'{key}={value}\n' for key, value in values.items()))
    print(f'Generated {len(buckets)} private bucket identities in {directory}; keep this directory private.')


if __name__ == '__main__':
    main()
