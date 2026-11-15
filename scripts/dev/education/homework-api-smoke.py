"""Bounded API-only smoke against the existing isolated local fixture stack."""

from __future__ import annotations

import datetime
from dataclasses import dataclass
from http.client import HTTPResponse
import json
from pathlib import Path
import subprocess
from typing import TypeAlias, TypedDict, cast
import urllib.error
import urllib.request
import uuid

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
            response = cast(HTTPResponse, urllib.request.urlopen(request, timeout=20))
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


def main() -> None:
    root = Path(__file__).resolve().parents[3]
    data_dir = root / "runtime/education-local/data"
    subprocess.run([
        "docker", "exec", "platform-education-local-backend-1", "php", "-r",
        "require '/education-tools/guard.php'; educationFixtureDatabase();",
    ], check=True, capture_output=True)
    fixture = decode_object((data_dir / "access.json").read_text(encoding="utf-8"), "fixture")
    demo = decode_object((data_dir / "education04-demo.json").read_text(encoding="utf-8"), "demo")
    client = ApiClient("http://127.0.0.1:8088", string_field(fixture, "organizationId"))
    request = client.request
    accounts = object_value(fixture["accounts"], "accounts")
    students = array_value(demo["studentAccounts"], "studentAccounts")
    teacher = client.login(object_value(accounts["teacher"], "teacher"))
    student = client.login(object_value(students[0], "studentAccounts[0]"))
    peer = client.login(object_value(students[1], "studentAccounts[1]"))
    groups = array_value(fixture["groups"], "groups")
    group = string_field(object_value(groups[0], "groups[0]"), "id")
    due = (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=2)).isoformat()
    evidence: JsonObject = {}

    detail = request("/api/education/homework", teacher, "POST", {
        "title": "API smoke · revision and files", "type": "text",
        "description": "Disposable local API scenario", "instructions": "Explain and attach a text file.",
        "groupId": group, "dueAt": due,
    }, 201)
    assignment_data = object_value(detail["assignment"], "assignment")
    assignment = string_field(assignment_data, "id")
    evidence["created"] = detail
    request(f"/api/education/homework/{assignment}/files", teacher, "POST", expected=201, upload="Teacher material")
    detail = request(f"/api/education/homework/{assignment}/publish", teacher, "POST", {
        "version": integer_field(assignment_data, "version"),
    })
    evidence["published"] = detail
    answer = request(f"/api/education/student/homework/{assignment}", student)
    evidence["notStarted"] = answer
    request(f"/api/education/student/homework/{assignment}/draft", student, "PUT", {
        "version": 0, "text": "A real API answer.",
    })
    file = request(f"/api/education/student/homework/{assignment}/files", student, "POST", expected=201, upload="Immutable submitted file")
    answer = request(f"/api/education/student/homework/{assignment}", student)
    submit: JsonObject = {"version": answer_version(answer), "idempotencyKey": str(uuid.uuid4())}
    answer = request(f"/api/education/student/homework/{assignment}/submit", student, "POST", submit)
    evidence["submitted"] = answer
    assert request(f"/api/education/student/homework/{assignment}/submit", student, "POST", submit) == answer
    file_id = string_field(file, "id")
    assert client.download(f"/api/education/homework/files/{file_id}", teacher) == b"Immutable submitted file"
    request(f"/api/education/homework/files/{file_id}", peer, expected=404)
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
    target = root / "runtime/education04/api-contract-evidence.json"
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
    print("API PASS: teacher -> student -> return -> resubmit -> accepted; immutable files/IDOR/tenant, Typing, notifications, progress, parents.")


if __name__ == "__main__":
    main()
