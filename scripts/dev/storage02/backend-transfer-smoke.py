"""Bounded Storage 02 signed HTTP transfers in the isolated Education project."""
from __future__ import annotations

import argparse
from contextlib import AbstractContextManager
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import time
from typing import Protocol, TypeAlias, TypedDict, cast
from urllib.parse import urlencode
import uuid


JsonValue: TypeAlias = str | int | float | bool | None | list['JsonValue'] | dict[str, 'JsonValue']
JsonObject: TypeAlias = dict[str, JsonValue]


class SmokeArguments(argparse.Namespace):
    runtime: Path
    evidence: Path | None
    exercise_outage: bool


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
    def integer_field(self, value: JsonObject, key: str) -> int: ...
    def array_value(self, value: JsonValue, context: str) -> list[JsonValue]: ...
    def verify_fixture(self, runtime: Path, *, with_s3: bool) -> FixtureRuntime: ...


class SignedRequest(TypedDict):
    url: str
    method: str
    headers: dict[str, str]


class FixtureRuntime(Protocol):
    @property
    def api_origin(self) -> str: ...

    def s3_offline(self) -> AbstractContextManager[None]: ...

    def signed(self, descriptor: SignedRequest, data: bytes | None = None,
               extra_headers: dict[str, str] | None = None) -> tuple[int, bytes, dict[str, str]]: ...


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
    parser.add_argument('--runtime', type=Path, default=Path(__file__).resolve().parents[3] / 'runtime/education-local')
    parser.add_argument('--evidence', type=Path)
    parser.add_argument('--exercise-outage', action='store_true', help='Restart only the verified isolated S3 service')
    args = parser.parse_args(namespace=SmokeArguments())
    helper_path = Path(__file__).parents[1] / 'education/homework-api-smoke.py'
    spec = importlib.util.spec_from_file_location('storage02_http_helper', helper_path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    sys.path.insert(0, str(helper_path.parent))
    try:
        spec.loader.exec_module(module)
    finally:
        sys.path.pop(0)
    helper = cast(HttpHelper, module)
    runtime = helper.verify_fixture(args.runtime, with_s3=True)
    fixture = helper.decode_object((args.runtime / 'data/access.json').read_text(encoding='utf-8'), 'fixture')
    demo = helper.decode_object((args.runtime / 'data/education04-demo.json').read_text(encoding='utf-8'), 'demo')
    client = helper.ApiClient(runtime.api_origin, helper.string_field(fixture, 'organizationId'))
    curriculum = helper.object_value(fixture['curriculum'], 'curriculum')
    accounts = helper.object_value(fixture['accounts'], 'accounts')
    student = client.login(helper.object_value(curriculum['studentAccount'], 'studentAccount'))
    teacher = client.login(helper.object_value(accounts['teacher'], 'teacher'))
    students = helper.array_value(demo['studentAccounts'], 'studentAccounts')
    peer = client.login(helper.object_value(students[1], 'second student'))
    req = client.request
    prefix = '/api/storage'
    namespace = 'platform-storage02-smoke/' + uuid.uuid4().hex
    checks: list[str] = []

    raw = runtime.signed

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
    unsigned_request: SignedRequest = {**down['request'], 'url': down['request']['url'].split('?', 1)[0]}
    assert raw(unsigned_request)[0] == 403
    checks.append('stale upload URL leaves final version immutable and download supports Range')
    checks.append('private S3 object rejects unsigned requests')
    req(prefix + f"/transfers/{single['id']}", teacher, expected=(403, 404))
    req(prefix + f"/transfers/{single['id']}", peer, expected=(403, 404))
    history_path = prefix + '/files/versions?' + urlencode({'path': path})
    assert req(history_path, peer)['items'] == []
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
    version_history = req(history_path, student)
    items = helper.array_value(version_history['items'], 'versions')
    assert {1, restored['version']} <= {
        helper.integer_field(helper.object_value(item, 'version'), 'version') for item in items
    }
    deleted = req(prefix + '/files', student, 'DELETE', {'path': path})
    assert deleted.get('deleted') is True
    req(prefix + '/tickets', student, 'POST', {'purpose': 'download', 'path': path}, expected=404)
    checks.append('verified historical S3 version, deletion and download revocation')
    if args.exercise_outage:
        recovery_path = namespace + '/recover.txt'
        recovery = cast(DirectTransfer, begin(ticket('upload', recovery_path, small)))
        assert raw(recovery['request'], small)[0] == 200
        with runtime.s3_offline():
            rejected = req(prefix + f"/transfers/{recovery['id']}/complete", student, 'POST', {}, expected=503)
            error = helper.object_value(rejected['error'], 'outage response')
            details = helper.object_value(error.get('meta'), 'outage reason')
            assert details.get('storageCode') == 's3_unavailable'
            req(prefix + '/tickets', student, 'POST', {'purpose': 'download', 'path': recovery_path}, expected=404)
        deadline = time.monotonic() + 45
        while True:
            confirmation = req(
                prefix + f"/transfers/{recovery['id']}/complete", student, 'POST', {}, expected=(200, 503),
            )
            if confirmation.get('status') == 'confirmed':
                break
            error = helper.object_value(confirmation.get('error'), 'recovery response')
            details = helper.object_value(error.get('meta'), 'recovery reason')
            assert details.get('storageCode') == 's3_unavailable'
            if time.monotonic() >= deadline:
                raise AssertionError('The same staged transfer was still unavailable after S3 recovery.')
            time.sleep(2)
        download(recovery_path, small)
        checks.append('S3 outage postpones publication and retries the same staged bytes after recovery')
    evidence = args.evidence or args.runtime / 'smoke/storage-transfer-evidence.json'
    evidence.parent.mkdir(parents=True, exist_ok=True)
    evidence.write_text(json.dumps({'checks': checks, 'multipartBytes': len(large)}, indent=2) + '\n', encoding='utf-8')
    print(f'Transfer API PASS {len(checks)} scenarios, signed single/multipart/resume/integrity/quota/conflict/restore.')


if __name__ == '__main__':
    main()
