"""Bounded real HTTP transfer checks against the dedicated STORAGE_02 test stack."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request
import uuid


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runtime', type=Path, required=True)
    parser.add_argument('--evidence', type=Path, required=True)
    args = parser.parse_args()
    subprocess.run(['docker', 'exec', 'storage02-test-backend-1', 'php', '-r',
        "if(getenv('STORAGE02_TEST_RUNTIME')!=='1'||getenv('DB_NAME')!=='platform_test'||!str_starts_with((string)getenv('S3_BUCKET_STORAGE'),'storage02-test-'))exit(2);"],
        check=True, capture_output=True)
    helper_path = Path(__file__).parents[1] / 'education/homework-api-smoke.py'
    spec = importlib.util.spec_from_file_location('storage02_http_helper', helper_path)
    assert spec and spec.loader
    helper = importlib.util.module_from_spec(spec); sys.modules[spec.name] = helper; spec.loader.exec_module(helper)
    fixture = json.loads((args.runtime / 'data/access.json').read_text(encoding='utf-8'))
    client = helper.ApiClient('http://127.0.0.1:8089', fixture['organizationId'])
    student = client.login(fixture['curriculum']['studentAccount'])
    teacher = client.login(fixture['accounts']['teacher'])
    req = client.request
    prefix = '/api/storage'
    namespace = 'platform-storage02-smoke/' + uuid.uuid4().hex
    checks: list[str] = []

    def raw(request: dict, data: bytes | None = None, extra_headers: dict[str, str] | None = None) -> tuple[int, bytes, dict[str, str]]:
        url = request['url']
        assert url.startswith(('http://localhost:18333/', 'http://127.0.0.1:18333/'))
        headers = {**request['headers'], **(extra_headers or {})}
        wire = urllib.request.Request(url, data=data, method=request['method'], headers=headers)
        try:
            with urllib.request.urlopen(wire, timeout=60) as response:
                return response.status, response.read(), {k.lower(): v for k, v in response.headers.items()}
        except urllib.error.HTTPError as error:
            return error.code, b'', {}
        except urllib.error.URLError as error:
            raise AssertionError('The isolated signed object request could not connect.') from None

    def ticket(path: str, content: bytes) -> dict:
        return req(prefix + '/tickets', student, 'POST', {'purpose': 'upload', 'path': path,
            'expectedSize': len(content), 'expectedSha256': hashlib.sha256(content).hexdigest()}, (200, 201))

    def begin(value: dict) -> dict:
        return req(prefix + '/transfers', student, 'POST', {'ticketId': value['id'], 'token': value['token']})

    def finish(transfer: dict) -> dict:
        return req(prefix + f"/transfers/{transfer['id']}/complete", student, 'POST', {})

    def download(path: str, content: bytes) -> dict:
        value = req(prefix + '/tickets', student, 'POST', {'purpose': 'download', 'path': path,
            'expectedSize': len(content), 'expectedSha256': hashlib.sha256(content).hexdigest()}, (200, 201))
        transfer = begin(value)
        status, body, _ = raw(transfer['request'])
        assert status == 200 and body == content
        assert hashlib.sha256(body).hexdigest() == transfer['expectedSha256']
        return transfer

    small = b'platform direct S3 transfer\n' * 127
    path = namespace + '/single.txt'
    single = begin(ticket(path, small))
    assert single['mode'] == 'single'
    assert raw(single['request'], small)[0] == 200
    confirmed = finish(single)
    assert confirmed['status'] == 'confirmed' and confirmed['result']['sha256'] == hashlib.sha256(small).hexdigest()
    assert finish(single) == confirmed
    checks.append('single direct upload and idempotent complete')
    # Surviving upload permission targets staging, so replay cannot mutate a published version.
    assert raw(single['request'], small)[0] == 200
    down = download(path, small)
    status, body, headers = raw(down['request'], extra_headers={'Range': 'bytes=7-31'})
    assert status == 206 and body == small[7:32]
    assert headers['content-range'] == f'bytes 7-31/{len(small)}'
    checks.append('stale upload URL leaves final version immutable and download supports Range')
    req(prefix + f"/transfers/{single['id']}", teacher, expected=(403, 404))
    req(prefix + f"/transfers/{single['id']}", student, expected=(403, 404), org=fixture['otherOrganizationId'])
    checks.append('current account and organization scope')

    large = b'A' * (12 * 1024 * 1024) + b'final multipart tail'
    multi = begin(ticket(namespace + '/multipart.bin', large))
    assert multi['mode'] == 'multipart' and multi['totalParts'] >= 2
    for number in range(1, multi['totalParts'] + 1):
        piece = large[(number - 1) * multi['partSize']:number * multi['partSize']]
        signed = req(prefix + f"/transfers/{multi['id']}/parts", student, 'POST', {'parts': [{
            'partNumber': number, 'sizeBytes': len(piece), 'sha256': hashlib.sha256(piece).hexdigest()}]})
        assert raw(signed['items'][0]['request'], piece)[0] == 200
        if number == 1:
            resumed = req(prefix + f"/transfers/{multi['id']}/refresh", student, 'POST', {})
            assert resumed['id'] == multi['id']
            assert resumed['completedParts'][0]['partNumber'] == 1
            assert resumed['completedParts'][0]['sha256'] == hashlib.sha256(piece).hexdigest()
    completed = finish(multi)
    assert completed['result']['size'] == len(large)
    assert completed['result']['sha256'] == hashlib.sha256(large).hexdigest()
    assert finish(multi) == completed
    download(namespace + '/multipart.bin', large)
    checks.append('real multipart parts, refresh/resume, full checksum, replay and download')

    quota = req(prefix + '/space', student)
    excessive = req(prefix + '/tickets', student, 'POST', {'purpose': 'upload', 'path': namespace + '/over-quota.bin',
        'expectedSize': quota['maxBytes'] + 1, 'expectedSha256': '1' * 64}, (200, 201))
    req(prefix + '/transfers', student, 'POST', {'ticketId': excessive['id'], 'token': excessive['token']}, expected=409)
    checks.append('quota reserved before signed upload permission')

    conflict_path = namespace + '/concurrent.txt'
    first_bytes, second_bytes = b'first complete file', b'second complete file'
    first_ticket, second_ticket = ticket(conflict_path, first_bytes), ticket(conflict_path, second_bytes)
    first = begin(first_ticket)
    req(prefix + '/transfers', student, 'POST', {'ticketId': second_ticket['id'], 'token': second_ticket['token']}, expected=409)
    assert raw(first['request'], first_bytes)[0] == 200
    finish(first)
    second = begin(second_ticket)
    assert raw(second['request'], second_bytes)[0] == 200
    req(prefix + f"/transfers/{second['id']}/complete", student, 'POST', {}, expected=409)
    cancelled = req(prefix + f"/transfers/{second['id']}/abort", student, 'POST', {})
    assert cancelled['status'] == 'aborted'
    download(conflict_path, first_bytes)
    checks.append('concurrent reservation and stale business version preserve committed bytes')

    restored = req(prefix + '/files/restore', student, 'POST', {'path': path, 'version': 1})
    assert restored['version'] >= 2 and restored['sha256'] == hashlib.sha256(small).hexdigest()
    download(path, small)
    checks.append('restore copies a verified historical S3 version')
    args.evidence.parent.mkdir(parents=True, exist_ok=True)
    args.evidence.write_text(json.dumps({'checks': checks, 'multipartBytes': len(large)}, indent=2) + '\n', encoding='utf-8')
    print(f'Transfer API PASS {len(checks)} scenarios, signed single/multipart/resume/integrity/quota/conflict/restore.')


if __name__ == '__main__':
    main()
