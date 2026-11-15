"""Generate separate local S3 application/initializer credentials; never print secrets or replace them on rerun."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import secrets
from urllib.parse import urlparse


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, default=Path('runtime/s3'))
    parser.add_argument('--test', action='store_true')
    parser.add_argument('--port', type=int, default=8333)
    parser.add_argument('--public-endpoint')
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
    prefix = 'storage02-test' if args.test else 'platform'
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
    config.write_text(json.dumps({'identities': identities}, indent=2) + '\n', encoding='utf-8')
    root = Path(__file__).resolve().parents[3]
    values = {
        'S3_CONFIG_FILE': config.as_posix(), 'S3_APP_BACKEND_PATH': (root / 'apps/backend').as_posix(),
        'S3_PORT': str(args.port), 'S3_ENDPOINT_INTERNAL': 'http://seaweedfs:8333',
        'S3_PUBLIC_ENDPOINT': endpoint, 'S3_REGION': 'us-east-1',
        'S3_BUCKET_STORAGE': buckets[0], 'S3_BUCKET_EDUCATION': buckets[1],
        'S3_ACCESS_KEY_ID': app_key, 'S3_SECRET_ACCESS_KEY': app_secret,
        'S3_INIT_ACCESS_KEY_ID': init_key, 'S3_INIT_SECRET_ACCESS_KEY': init_secret,
        'S3_PATH_STYLE': 'true', 'S3_PRESIGN_TTL_SECONDS': '120', 'S3_MULTIPART_PART_SIZE': '8388608',
        'S3_UPLOAD_MAX_BYTES': '10737418240', 'S3_TRANSFER_CONCURRENCY': '3',
        'S3_CORS_ORIGINS': 'http://localhost:5174,http://127.0.0.1:5174,http://localhost:5173',
    }
    env.write_text(''.join(f'{key}={value}\n' for key, value in values.items()), encoding='utf-8')
    config.chmod(0o600)
    env.chmod(0o600)
    print(f'Generated {len(buckets)} private bucket identities in {directory}; keep this directory private.')


if __name__ == '__main__':
    main()
