"""Bounded real HTTP transfer checks against the dedicated STORAGE_02 test stack."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
from typing import Protocol, TypeAlias, TypedDict, cast
import urllib.error
import urllib.request
import uuid


JsonValue: TypeAlias = str | int | float | bool | None | list['JsonValue'] | dict[str, 'JsonValue']
JsonObject: TypeAlias = dict[str, JsonValue]


class SmokeArguments(argparse.Namespace):
    runtime: Path
    evidence: Path


class Actor(TypedDict):
    token: str
    client: str


class ApiClient(Protocol):
    def login(self, account: JsonObject) -> Actor: ...

    def request(
        self, path: str, actor: Actor, method: str = 'GET', body: JsonObject | None = None,
        expected: int | tuple[int, ...] = 200, org: str | None = None,
    ) -> JsonObject: ...


class HttpHelper(Protocol):
    def ApiClient(self, base: str, organization_id: str) -> ApiClient: ...
    def decode_object(self, raw: str | bytes, context: str) -> JsonObject: ...
    def object_value(self, value: JsonValue, context: str) -> JsonObject: ...
    def string_field(self, value: JsonObject, key: str) -> str: ...


class SignedRequest(TypedDict):
    url: str
    method: str
    headers: dict[str, str]


class SignedPart(TypedDict):
    request: SignedRequest


class CompletedPart(TypedDict):
    partNumber: int
    sha256: str


class TransferResult(TypedDict):
    size: int
    sha256: str


class Ticket(TypedDict):
    id: str
    token: str


class Transfer(TypedDict):
    id: str
    mode: str


class DirectTransfer(Transfer):
    request: SignedRequest
    expectedSha256: str


class MultipartTransfer(Transfer):
    totalParts: int
    partSize: int


class Status(TypedDict):
    status: str


class Confirmation(Status):
    result: TransferResult


class SignedParts(TypedDict):
    items: list[SignedPart]


class ResumedTransfer(Transfer):
    completedParts: list[CompletedPart]


class Quota(TypedDict):
    maxBytes: int


class RestoredFile(TypedDict):
    version: int
    sha256: str


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runtime', type=Path, required=True)
    parser.add_argument('--evidence', type=Path, required=True)
    args = parser.parse_args(namespace=SmokeArguments())
    subprocess.run(['docker', 'exec', 'storage02-test-backend-1', 'php', '-r',
        "if(getenv('STORAGE02_TEST_RUNTIME')!=='1'||getenv('DB_NAME')!=='platform_test'||!str_starts_with((string)getenv('S3_BUCKET_STORAGE'),'storage02-test-'))exit(2);"],
        check=True, capture_output=True)
    helper_path = Path(__file__).parents[1] / 'education/homework-api-smoke.py'
    spec = importlib.util.spec_from_file_location('storage02_http_helper', helper_path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    helper = cast(HttpHelper, module)
    fixture = helper.decode_object((args.runtime / 'data/access.json').read_text(encoding='utf-8'), 'fixture')
    client = helper.ApiClient('http://127.0.0.1:8089', helper.string_field(fixture, 'organizationId'))
    curriculum = helper.object_value(fixture['curriculum'], 'curriculum')
    accounts = helper.object_value(fixture['accounts'], 'accounts')
    student = client.login(helper.object_value(curriculum['studentAccount'], 'studentAccount'))
    teacher = client.login(helper.object_value(accounts['teacher'], 'teacher'))
    req = client.request
    prefix = '/api/storage'
    namespace = 'platform-storage02-smoke/' + uuid.uuid4().hex
    checks: list[str] = []

    def raw(request: SignedRequest, data: bytes | None = None, extra_headers: dict[str, str] | None = None) -> tuple[int, bytes, dict[str, str]]:
        url = request['url']
        assert url.startswith(('http://localhost:18333/', 'http://127.0.0.1:18333/'))
        headers = {**request['headers'], **(extra_headers or {})}
        wire = urllib.request.Request(url, data=data, method=request['method'], headers=headers)
        try:
            with urllib.request.urlopen(wire, timeout=60) as response:
                return response.status, response.read(), {k.lower(): v for k, v in response.headers.items()}
        except urllib.error.HTTPError as error:
            return error.code, b'', {}
        except urllib.error.URLError:
            raise AssertionError('The isolated signed object request could not connect.') from None

    def ticket(purpose: str, path: str, content: bytes) -> Ticket:
        return cast(Ticket, req(prefix + '/tickets', student, 'POST', {'purpose': purpose, 'path': path,
            'expectedSize': len(content), 'expectedSha256': hashlib.sha256(content).hexdigest()}, (200, 201)))

    def begin(value: Ticket) -> Transfer:
        return cast(Transfer, req(prefix + '/transfers', student, 'POST', {'ticketId': value['id'], 'token': value['token']}))

    def finish(transfer: Transfer) -> Confirmation:
        return cast(Confirmation, req(prefix + f"/transfers/{transfer['id']}/complete", student, 'POST', {}))

    def download(path: str, content: bytes) -> DirectTransfer:
        transfer = cast(DirectTransfer, begin(ticket('download', path, content)))
        status, body, _ = raw(transfer['request'])
        assert status == 200 and body == content
        assert hashlib.sha256(body).hexdigest() == transfer['expectedSha256']
        return transfer

    small = b'platform direct S3 transfer\n' * 127
    path = namespace + '/single.txt'
    single = cast(DirectTransfer, begin(ticket('upload', path, small)))
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
    req(prefix + f"/transfers/{single['id']}", student, expected=(403, 404),
        org=helper.string_field(fixture, 'otherOrganizationId'))
    checks.append('current account and organization scope')

    large = b'A' * (12 * 1024 * 1024) + b'final multipart tail'
    multi = cast(MultipartTransfer, begin(ticket('upload', namespace + '/multipart.bin', large)))
    assert multi['mode'] == 'multipart' and multi['totalParts'] >= 2
    for number in range(1, multi['totalParts'] + 1):
        piece = large[(number - 1) * multi['partSize']:number * multi['partSize']]
        signed = cast(SignedParts, req(prefix + f"/transfers/{multi['id']}/parts", student, 'POST', {'parts': [{
            'partNumber': number, 'sizeBytes': len(piece), 'sha256': hashlib.sha256(piece).hexdigest()}]}))
        assert raw(signed['items'][0]['request'], piece)[0] == 200
        if number == 1:
            resumed = cast(ResumedTransfer, req(prefix + f"/transfers/{multi['id']}/refresh", student, 'POST', {}))
            assert resumed['id'] == multi['id']
            assert resumed['completedParts'][0]['partNumber'] == 1
            assert resumed['completedParts'][0]['sha256'] == hashlib.sha256(piece).hexdigest()
    completed = finish(multi)
    assert completed['result']['size'] == len(large)
    assert completed['result']['sha256'] == hashlib.sha256(large).hexdigest()
    assert finish(multi) == completed
    download(namespace + '/multipart.bin', large)
    checks.append('real multipart parts, refresh/resume, full checksum, replay and download')

    quota = cast(Quota, req(prefix + '/space', student))
    excessive = cast(Ticket, req(prefix + '/tickets', student, 'POST', {'purpose': 'upload', 'path': namespace + '/over-quota.bin',
        'expectedSize': quota['maxBytes'] + 1, 'expectedSha256': '1' * 64}, (200, 201)))
    req(prefix + '/transfers', student, 'POST', {'ticketId': excessive['id'], 'token': excessive['token']}, expected=409)
    checks.append('quota reserved before signed upload permission')

    conflict_path = namespace + '/concurrent.txt'
    first_bytes, second_bytes = b'first complete file', b'second complete file'
    first_ticket = ticket('upload', conflict_path, first_bytes)
    second_ticket = ticket('upload', conflict_path, second_bytes)
    first = cast(DirectTransfer, begin(first_ticket))
    req(prefix + '/transfers', student, 'POST', {'ticketId': second_ticket['id'], 'token': second_ticket['token']}, expected=409)
    assert raw(first['request'], first_bytes)[0] == 200
    finish(first)
    second = cast(DirectTransfer, begin(second_ticket))
    assert raw(second['request'], second_bytes)[0] == 200
    req(prefix + f"/transfers/{second['id']}/complete", student, 'POST', {}, expected=409)
    cancelled = cast(Status, req(prefix + f"/transfers/{second['id']}/abort", student, 'POST', {}))
    assert cancelled['status'] == 'aborted'
    download(conflict_path, first_bytes)
    checks.append('concurrent reservation and stale business version preserve committed bytes')

    restored = cast(RestoredFile, req(prefix + '/files/restore', student, 'POST', {'path': path, 'version': 1}))
    assert restored['version'] >= 2 and restored['sha256'] == hashlib.sha256(small).hexdigest()
    download(path, small)
    checks.append('restore copies a verified historical S3 version')
    args.evidence.parent.mkdir(parents=True, exist_ok=True)
    args.evidence.write_text(json.dumps({'checks': checks, 'multipartBytes': len(large)}, indent=2) + '\n', encoding='utf-8')
    print(f'Transfer API PASS {len(checks)} scenarios, signed single/multipart/resume/integrity/quota/conflict/restore.')


if __name__ == '__main__':
    main()
