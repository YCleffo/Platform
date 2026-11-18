"""Bounded API-only smoke against the existing isolated local fixture stack."""

from __future__ import annotations

import argparse
import datetime
from dataclasses import dataclass
import hashlib
from http.client import HTTPResponse
import json
from pathlib import Path
from typing import TypeAlias, TypedDict, cast
import urllib.error
import urllib.request
import uuid

from smoke_runtime import (
    DEFAULT_RUNTIME,
    FixtureRuntime,
    SignedRequest,
    SmokeEnvironmentError,
    opener,
    verify_fixture,
)

JsonValue: TypeAlias = (
    str | int | float | bool | None | list["JsonValue"] | dict[str, "JsonValue"]
)
JsonObject: TypeAlias = dict[str, JsonValue]


class Actor(TypedDict):
    token: str
    client: str


def object_value(value: JsonValue, context: str) -> JsonObject:
    if not isinstance(value, dict):
        raise AssertionError(f"{context}: expected a JSON object")
    return value


def array_value(value: JsonValue, context: str) -> list[JsonValue]:
    if not isinstance(value, list):
        raise AssertionError(f"{context}: expected a JSON array")
    return value


def string_field(value: JsonObject, key: str) -> str:
    field = value[key]
    if not isinstance(field, str):
        raise AssertionError(f"{key}: expected a string")
    return field


def integer_field(value: JsonObject, key: str) -> int:
    field = value[key]
    if isinstance(field, bool) or not isinstance(field, int):
        raise AssertionError(f"{key}: expected an integer")
    return field


def decode_object(raw: str | bytes, context: str) -> JsonObject:
    # The standard JSON decoder produces only JSON scalar/container values.
    value = cast(JsonValue, json.loads(raw))
    return object_value(value, context)


def answer_version(answer: JsonObject) -> int:
    return integer_field(object_value(answer["submission"], "submission"), "version")


@dataclass(frozen=True)
class ApiResponse:
    status: int
    content_type: str
    body: bytes


@dataclass(frozen=True)
class ApiClient:
    base: str
    organization_id: str

    def _request(
        self,
        path: str,
        actor: Actor | None = None,
        method: str = "GET",
        body: JsonObject | None = None,
        expected: int | tuple[int, ...] = 200,
        org: str | None = None,
        upload: str | None = None,
    ) -> ApiResponse:
        headers = {
            "Accept": "application/json",
            "X-Client-Instance-ID": actor["client"] if actor else str(uuid.uuid4()),
            "User-Agent": "Education04 local API verification",
        }
        if actor:
            headers.update({
                "Authorization": "Bearer " + actor["token"],
                "X-Education-Organization": org or self.organization_id,
            })
        encoded: bytes | None = None
        if upload is not None:
            boundary = "Education04" + uuid.uuid4().hex
            encoded = (
                f'--{boundary}\r\nContent-Disposition: form-data; name="file"; '
                'filename="answer.txt"\r\nContent-Type: text/plain\r\n\r\n'
                + upload + f"\r\n--{boundary}--\r\n"
            ).encode()
            headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
        elif body is not None:
            encoded = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(
            self.base + path, data=encoded, headers=headers, method=method,
        )
        try:
            response = cast(HTTPResponse, opener.open(request, timeout=30))
        except urllib.error.HTTPError as error:
            with error:
                result = ApiResponse(error.code, error.headers.get_content_type(), error.read())
                cache_control = str(error.headers.get("Cache-Control", ""))
        else:
            with response:
                result = ApiResponse(response.status, response.headers.get_content_type(), response.read())
                cache_control = str(response.headers.get("Cache-Control", ""))
        allowed = expected if isinstance(expected, tuple) else (expected,)
        if result.status not in allowed:
            payload = decode_object(result.body, path) if result.body.startswith(b"{") else {}
            error_payload = payload.get("error")
            metadata = error_payload.get("meta") if isinstance(error_payload, dict) else None
            raise AssertionError(
                f"{method} {path}: status {result.status}, expected {expected}; code={metadata}"
            )
        if path.startswith("/api/education"):
            assert "no-store" in cache_control
        return result

    def request(
        self,
        path: str,
        actor: Actor | None = None,
        method: str = "GET",
        body: JsonObject | None = None,
        expected: int | tuple[int, ...] = 200,
        org: str | None = None,
        upload: str | None = None,
    ) -> JsonObject:
        result = self._request(path, actor, method, body, expected, org, upload)
        assert result.content_type == "application/json", f"{path}: expected JSON"
        envelope = decode_object(result.body, path)
        if result.status >= 400:
            return envelope
        return object_value(envelope.get("data"), path + ".data")

    def download(self, path: str, actor: Actor) -> bytes:
        return self._request(path, actor).body

    def login(self, account: JsonObject) -> Actor:
        value = self.request("/api/auth/login", method="POST", body={
            "identifierType": "email",
            "identifier": string_field(account, "email"),
            "password": string_field(account, "password"),
        })
        return {"token": string_field(value, "accessToken"), "client": str(uuid.uuid4())}


def upload_signed(
    client: ApiClient, runtime: FixtureRuntime, actor: Actor, path: str, content: bytes,
    name: str, *, lesson_id: str | None = None,
) -> JsonObject:
    sha256 = hashlib.sha256(content).hexdigest()
    body: JsonObject = {"name": name, "sizeBytes": len(content), "sha256": sha256}
    if lesson_id is not None:
        body["lessonId"] = lesson_id
    transfer = client.request(path, actor, "POST", body, 201)
    target = f"/api/education/file-transfers/{string_field(transfer, 'id')}/complete"
    assert transfer["status"] == "pending"
    assert transfer["expectedSize"] == len(content) and transfer["expectedSha256"] == sha256
    status, _, _ = runtime.signed(cast(SignedRequest, transfer["request"]), content)
    assert status == 200, f"Signed upload returned {status}."
    complete = client.request(target, actor, "POST", {})
    assert complete["status"] == "confirmed"
    assert client.request(target, actor, "POST", {}) == complete
    return object_value(complete["result"], "confirmed Education file")


def check_signed_download(
    client: ApiClient, runtime: FixtureRuntime, actor: Actor, path: str, content: bytes,
) -> None:
    transfer = client.request(path, actor)
    expected = hashlib.sha256(content).hexdigest()
    status, received, _ = runtime.signed(cast(SignedRequest, transfer["request"]))
    assert status == 200 and received == content, f"Signed download returned {status} or mismatched bytes."
    assert transfer["sizeBytes"] == len(received) and transfer["sha256"] == expected


class Arguments(argparse.Namespace):
    runtime: Path
    evidence: Path | None


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime", type=Path, default=DEFAULT_RUNTIME)
    parser.add_argument("--evidence", type=Path)
    args = parser.parse_args(namespace=Arguments())
    runtime = verify_fixture(args.runtime, with_s3=True)
    data_dir = args.runtime / "data"
    fixture = decode_object((data_dir / "access.json").read_text(encoding="utf-8"), "fixture")
    demo = decode_object((data_dir / "education04-demo.json").read_text(encoding="utf-8"), "demo")
    client = ApiClient(runtime.api_origin, string_field(fixture, "organizationId"))
    request = client.request
    accounts = object_value(fixture["accounts"], "accounts")
    students = array_value(demo["studentAccounts"], "studentAccounts")
    teacher = client.login(object_value(accounts["teacher"], "teacher"))
    student = client.login(object_value(students[0], "studentAccounts[0]"))
    peer = client.login(object_value(students[1], "studentAccounts[1]"))
    groups = array_value(fixture["groups"], "groups")
    group = string_field(object_value(groups[0], "groups[0]"), "id")
    now = datetime.datetime.now(datetime.timezone.utc)
    due = (now + datetime.timedelta(days=2)).isoformat()
    evidence: JsonObject = {}
    teacher_group = request(f"/api/education/groups/{group}", teacher)
    assert teacher_group
    curriculum = object_value(fixture["curriculum"], "curriculum")
    lessons = array_value(curriculum["scheduled"], "scheduled lessons")
    lesson = string_field(object_value(lessons[0], "first lesson"), "id")
    lesson_material = b"Isolated classroom S3 material.\n"
    material = upload_signed(client, runtime, teacher, "/api/education/file-transfers", lesson_material,
                             "classroom-smoke.txt", lesson_id=lesson)
    material_id = string_field(material, "id")
    lesson_detail = request(f"/api/education/student/lessons/{lesson}", student)
    assert any(object_value(item, "lesson material")["id"] == material_id
               for item in array_value(lesson_detail["materials"], "lesson materials"))
    material_path = f"/api/education/files/{material_id}/transfer"
    check_signed_download(client, runtime, teacher, material_path, lesson_material)
    check_signed_download(client, runtime, student, material_path, lesson_material)
    request(material_path, student, expected=(403, 404), org=string_field(fixture, "otherOrganizationId"))
    outsider = client.login(object_value(students[2], "studentAccounts[2]"))
    request(material_path, outsider, expected=(403, 404))
    evidence["lessonMaterial"] = material_id

    detail = request("/api/education/homework", teacher, "POST", {
        "title": "API smoke · revision and files", "type": "text",
        "description": "Disposable local API scenario", "instructions": "Explain and attach a text file.",
        "groupId": group, "dueAt": due,
    }, 201)
    assignment_data = object_value(detail["assignment"], "assignment")
    assignment = string_field(assignment_data, "id")
    evidence["created"] = detail
    teacher_material = b"Isolated homework S3 material.\n"
    material = upload_signed(client, runtime, teacher, f"/api/education/homework/{assignment}/file-transfers",
                             teacher_material, "teacher-material.txt")
    detail = request(f"/api/education/homework/{assignment}/publish", teacher, "POST", {
        "version": integer_field(assignment_data, "version"),
    })
    evidence["published"] = detail
    answer = request(f"/api/education/student/homework/{assignment}", student)
    evidence["notStarted"] = answer
    check_signed_download(client, runtime, student,
                          f"/api/education/homework/files/{string_field(material, 'id')}/transfer", teacher_material)
    request(f"/api/education/student/homework/{assignment}/draft", student, "PUT", {
        "version": 0, "text": "A real API answer.",
    })
    submitted_bytes = b"Immutable submitted file.\n"
    file = upload_signed(client, runtime, student, f"/api/education/student/homework/{assignment}/file-transfers",
                         submitted_bytes, "answer.txt")
    answer = request(f"/api/education/student/homework/{assignment}", student)
    submit: JsonObject = {"version": answer_version(answer), "idempotencyKey": str(uuid.uuid4())}
    answer = request(f"/api/education/student/homework/{assignment}/submit", student, "POST", submit)
    evidence["submitted"] = answer
    assert request(f"/api/education/student/homework/{assignment}/submit", student, "POST", submit) == answer
    file_id = string_field(file, "id")
    check_signed_download(client, runtime, teacher, f"/api/education/homework/files/{file_id}/transfer", submitted_bytes)
    request(f"/api/education/homework/files/{file_id}/transfer", peer, expected=404)
    request(f"/api/education/homework/{assignment}", teacher, expected=(403, 404), org=string_field(fixture, "otherOrganizationId"))
    submission = string_field(object_value(answer["submission"], "submission"), "id")
    answer = request(f"/api/education/homework/submissions/{submission}/review", teacher, "POST", {
        "version": answer_version(answer), "idempotencyKey": str(uuid.uuid4()),
        "decision": "returned", "comment": "Add one example.",
    })
    answer = request(f"/api/education/student/homework/{assignment}/draft", student, "PUT", {
        "version": answer_version(answer), "text": "Improved answer with an example.",
    })
    answer = request(f"/api/education/student/homework/{assignment}/submit", student, "POST", {
        "version": answer_version(answer), "idempotencyKey": str(uuid.uuid4()),
    })
    answer = request(f"/api/education/homework/submissions/{submission}/review", teacher, "POST", {
        "version": answer_version(answer), "idempotencyKey": str(uuid.uuid4()),
        "decision": "accepted", "resultStatus": "mastered", "comment": "Accepted.",
    })
    submitted = object_value(answer["submission"], "submission")
    assert len(array_value(submitted["versions"], "versions")) == 2
    assert len(array_value(submitted["reviews"], "reviews")) == 2
    evidence["reviewed"] = answer

    detail = request("/api/education/homework", teacher, "POST", {
        "title": "API smoke · assigned Typing", "type": "typing", "groupId": group,
        "dueAt": due, "maxAttempts": 3, "attemptPolicy": "best",
        "activityConfig": {"durationSeconds": 15, "language": "ru"},
    }, 201)
    assignment_data = object_value(detail["assignment"], "assignment")
    typing_id = string_field(assignment_data, "id")
    request(f"/api/education/homework/{typing_id}/publish", teacher, "POST", {"version": integer_field(assignment_data, "version")})
    started = request(f"/api/education/student/homework/{typing_id}/activity/start", student, "POST", {})
    exercise = object_value(started["exercise"], "exercise")
    attempt_id = string_field(object_value(started["attempt"], "attempt"), "id")
    assert exercise["durationSeconds"] == 15 and exercise["language"] == "ru"
    request(f"/api/education/student/attempts/{attempt_id}/finish", student, "PUT", {
        "typed": string_field(exercise, "text")[:35], "durationSeconds": 15,
    })
    answer = request(f"/api/education/student/homework/{typing_id}/activity/attach", student, "POST", {"attemptId": attempt_id})
    activity_result = object_value(object_value(answer["submission"], "submission")["activityResult"], "activityResult")
    assert activity_result["attemptId"] == attempt_id
    answer = request(f"/api/education/student/homework/{typing_id}/submit", student, "POST", {
        "version": answer_version(answer), "idempotencyKey": str(uuid.uuid4()),
    })
    evidence["typing"] = answer
    first_student = object_value(array_value(fixture["students"], "students")[0], "students[0]")
    student_id = string_field(first_student, "id")
    scenarios: list[tuple[str, str, Actor]] = [
        ("teacherList", "/homework", teacher), ("studentList", "/student/homework", student),
        ("studentProgress", "/student/homework/progress", student), ("teacherProgress", "/homework/progress", teacher),
        ("history", f"/homework/{assignment}/history", teacher), ("recipients", f"/homework/{assignment}/submissions", teacher),
        ("studentNotifications", "/student/notifications", student), ("teacherNotifications", "/notifications", teacher),
        ("studentCard", f"/students/{student_id}", teacher),
    ]
    for key, path, actor in scenarios:
        evidence[key] = request("/api/education" + path, actor)
    assert object_value(evidence["studentCard"], "studentCard")["guardians"]
    target = args.evidence or args.runtime / "smoke/homework-api-evidence.json"
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
    print("API PASS: teacher -> student -> return -> resubmit -> accepted; immutable files/IDOR/tenant, Typing, notifications, progress, parents.")


if __name__ == "__main__":
    try:
        main()
    except SmokeEnvironmentError as error:
        raise SystemExit(str(error)) from None
