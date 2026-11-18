"""Bounded family HTTP scenario; runs only against the guarded isolated Education stack."""

from __future__ import annotations

import argparse
import datetime
import importlib.util
import json
from pathlib import Path
import secrets
import sys
from typing import TypedDict
import uuid

from smoke_runtime import DEFAULT_RUNTIME, SmokeEnvironmentError, verify_fixture


class SmokeEvidence(TypedDict):
    checks: list[str]
    ids: dict[str, str]


class Arguments(argparse.Namespace):
    runtime: Path
    evidence_dir: Path | None


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime", type=Path, default=DEFAULT_RUNTIME)
    parser.add_argument("--evidence-dir", type=Path)
    args = parser.parse_args(namespace=Arguments())
    runtime = verify_fixture(args.runtime)
    source = Path(__file__).with_name("homework-api-smoke.py")
    spec = importlib.util.spec_from_file_location("education_homework_smoke", source)
    if spec is None or spec.loader is None:
        raise RuntimeError("Cannot load the existing isolated HTTP client.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    data = args.runtime / "data"
    access = json.loads((data / "access.json").read_text(encoding="utf-8"))
    demo = json.loads((data / "education04-demo.json").read_text(encoding="utf-8"))
    client = module.ApiClient(runtime.api_origin, access["organizationId"])
    request = client.request
    teacher = client.login(access["accounts"]["teacher"])
    first = demo["students"][0]
    second = demo["students"][1]
    unrelated = demo["students"][2]
    learner = client.login(demo["studentAccounts"][0])
    guardian = demo["parents"][0]["personId"]
    result: SmokeEvidence = {"checks": [], "ids": {"firstChild": first["id"], "secondChild": second["id"], "guardianPerson": guardian}}

    def passed(name: str) -> None:
        result["checks"].append(name)

    def family_path(student: dict[str, str], person: bool = False) -> str:
        path = f'/api/education/students/{student["id"]}/guardians'
        return path + (f"/{guardian}" if person else "")

    def confirm(student: dict[str, str], relationship: str) -> None:
        rows = request(family_path(student), teacher)["items"]
        row = next((item for item in rows if item["personId"] == guardian), None)
        if row is None:
            request(family_path(student), teacher, "POST", {"personId": guardian, "relationship": relationship}, (200, 201))
        else:
            request(family_path(student, True), teacher, "PUT", {"relationship": relationship})
        if row is None or row["status"] != "confirmed":
            request(family_path(student, True) + "/confirm", teacher, "POST", {})

    confirm(first, "mother")
    first_invite = request(family_path(first, True) + "/invite", teacher, "POST", {}, (200, 201))
    invite = request(family_path(first, True) + "/invite", teacher, "POST", {}, (200, 201))
    # Keep login material in one ignored local file for manual visual checks and safe reruns.
    output = args.evidence_dir or args.runtime / "smoke"
    output.mkdir(parents=True, exist_ok=True)
    credential_file = output / "local-parent-access.json"
    previous = args.runtime.parent / "education05/local-parent-access.json"
    previous_access = credential_file if credential_file.exists() else previous
    saved_credentials = json.loads(previous_access.read_text(encoding="utf-8")) if previous_access.is_file() else None
    if saved_credentials is not None and saved_credentials.get("personId") == guardian:
        credentials = saved_credentials
        parent = client.login(credentials)
        request("/api/education/parent/activate", parent, "POST", {"code": invite["code"]})
        if previous_access != credential_file:
            credential_file.write_text(json.dumps(credentials, indent=2), encoding="utf-8")
    else:
        credentials = {"personId": guardian, "email": "parent-education05-" + uuid.uuid4().hex + "@example.test", "password": secrets.token_urlsafe(24)}
        registered = request("/api/education/parent/register", method="POST", body={"code": invite["code"], "email": credentials["email"], "password": credentials["password"]}, expected=(200, 201))
        assert registered["activated"] is True
        credential_file.write_text(json.dumps(credentials, indent=2), encoding="utf-8")
        parent = client.login(credentials)
    passed("exact Core person register/login/activation")
    request("/api/education/parent/activate", parent, "POST", {"code": invite["code"]}, (404, 409))
    request("/api/education/parent/activate", parent, "POST", {"code": first_invite["code"]}, (404, 409))
    passed("one-use and superseded invitations rejected")
    revoked_invite = request(family_path(first, True) + "/invite", teacher, "POST", {}, (200, 201))
    request(family_path(first, True) + "/invite/revoke", teacher, "POST", {})
    request("/api/education/parent/activate", parent, "POST", {"code": revoked_invite["code"]}, (404, 409))
    passed("revoked invitation rejected")

    confirm(second, "guardian")
    second_invite = request(family_path(second, True) + "/invite", teacher, "POST", {}, (200, 201))
    request("/api/education/parent/activate", parent, "POST", {"code": second_invite["code"]})
    children = request("/api/education/parent/context", parent)["children"]
    assert {first["id"], second["id"]}.issubset({item["id"] for item in children})
    first_path = f'/api/education/parent/children/{first["id"]}'
    second_path = f'/api/education/parent/children/{second["id"]}'
    assert request(first_path + "/home", parent)["child"]["id"] == first["id"]
    assert request(second_path + "/home", parent)["child"]["id"] == second["id"]
    passed("two children scoped independently")

    now = datetime.datetime.now(datetime.timezone.utc)
    due = (now + datetime.timedelta(days=2)).isoformat()
    detail = request("/api/education/homework", teacher, "POST", {
        "title": "Education05 family HTTP smoke", "description": "Fictional isolated family scenario",
        "instructions": "Describe a loop.", "type": "text", "groupId": None,
        "studentIds": [first["id"]], "dueAt": due,
    }, 201)
    assignment = detail["assignment"]
    aid = assignment["id"]
    result["ids"]["assignment"] = aid
    request(f"/api/education/homework/{aid}/publish", teacher, "POST", {"version": assignment["version"]})
    request(f"/api/education/student/homework/{aid}/draft", learner, "PUT", {"version": 0, "text": "PRIVATE_FAMILY_SMOKE_DRAFT"})
    projection = request(first_path + "/homework", parent)
    assert any(item["id"] == aid for item in projection["items"])
    assert "PRIVATE_FAMILY_SMOKE_DRAFT" not in json.dumps(projection)
    assert all(item["id"] != aid for item in request(second_path + "/homework", parent)["items"])
    passed("published homework visible only to recipient family; drafts hidden")
    answer = request(f"/api/education/student/homework/{aid}", learner)
    submitted = request(f"/api/education/student/homework/{aid}/submit", learner, "POST", {"version": answer["submission"]["version"], "idempotencyKey": str(uuid.uuid4())})
    sid = submitted["submission"]["id"]
    request(f"/api/education/homework/submissions/{sid}/review", teacher, "POST", {
        "version": submitted["submission"]["version"], "idempotencyKey": str(uuid.uuid4()),
        "decision": "accepted", "resultStatus": "mastered", "comment": "Visible family HTTP feedback",
    })
    reviewed = request(first_path + "/homework", parent)
    row = next(item for item in reviewed["items"] if item["id"] == aid)
    assert row["submissionState"] == "REVIEWED" and row["review"]["resultStatus"] == "mastered"
    assert row["review"]["comment"] == "Visible family HTTP feedback"
    assert "PRIVATE_FAMILY_SMOKE_DRAFT" not in json.dumps(reviewed)
    passed("actual reviewed result visible with family feedback")

    request(f"/api/education/student/homework/{aid}/draft", parent, "PUT", {"version": 1, "text": "Parent impersonation"}, (403, 404))
    request(f"/api/education/student/homework/{aid}/submit", parent, "POST", {"version": 1, "idempotencyKey": str(uuid.uuid4())}, (403, 404))
    request("/api/storage/files", parent, expected=(403, 404))
    request("/api/storage/space", parent, expected=(403, 404))
    request(f'/api/education/parent/children/{unrelated["id"]}/home', parent, expected=(403, 404))
    request(first_path + "/home", parent, expected=(403, 404), org=access["otherOrganizationId"])
    request(family_path(first), parent, expected=(403, 404))
    passed("student writes, personal Documents, foreign child, foreign organization and staff actions denied")

    start = now + datetime.timedelta(days=1)
    # Reruns retain earlier fixture edits. Find a free slot instead of colliding with a prior smoke lesson.
    occupied = request(f'/api/education/schedule?from={start.date().isoformat()}&to={(start + datetime.timedelta(days=7)).date().isoformat()}', teacher)
    for existing in sorted(occupied['lessons'], key=lambda lesson: lesson['startsAt']):
        if existing['status'] != 'scheduled':
            continue
        busy_start = datetime.datetime.fromisoformat(existing['startsAt'].replace('Z', '+00:00'))
        busy_end = datetime.datetime.fromisoformat(existing['endsAt'].replace('Z', '+00:00'))
        if start < busy_end and start + datetime.timedelta(hours=1) > busy_start:
            start = busy_end
    end = start + datetime.timedelta(hours=1)
    group = demo["groups"][0]["id"]
    lesson_body = {"groupId": group, "title": "Family HTTP schedule", "startsAt": start.isoformat(), "endsAt": end.isoformat(), "location": "Fictional room"}
    lesson = request("/api/education/lessons", teacher, "POST", lesson_body, 201)
    lesson_body["title"] = "Changed family HTTP schedule"
    request(f'/api/education/lessons/{lesson["id"]}', teacher, "PUT", lesson_body)
    tomorrow = start.date().isoformat()
    schedule = request(first_path + f"/schedule?from={tomorrow}&to={tomorrow}", parent)
    assert any(item["id"] == lesson["id"] for item in schedule["items"])
    kinds = {item["kind"] for item in request(first_path + "/notifications", parent)["items"]}
    assert {"parent_homework_published", "parent_homework_reviewed", "parent_schedule_changed"}.issubset(kinds)
    assert "message" not in kinds and "homework_submitted" not in kinds
    for section in ["diary", "progress"]:
        request(first_path + "/" + section, parent)
    passed("schedule, diary, curriculum/progress and explicit family notifications")

    before = request(f"/api/education/homework/submissions/{sid}", teacher)
    before.pop("now", None)  # Response observation time is deliberately different for each request.
    history_before = request(f'/api/education/students/{first["id"]}/results', teacher)
    request(family_path(first, True) + "/revoke", teacher, "POST", {})
    request(first_path + "/home", parent, expected=(403, 404))
    request(first_path + "/homework", parent, expected=(403, 404))
    request(first_path + "/notifications", parent, expected=(403, 404))
    after_children = request("/api/education/parent/context", parent)["children"]
    assert first["id"] not in {child["id"] for child in after_children}
    assert second["id"] in {child["id"] for child in after_children}
    after = request(f"/api/education/homework/submissions/{sid}", teacher)
    after.pop("now", None)
    assert before == after
    assert history_before == request(f'/api/education/students/{first["id"]}/results', teacher)
    family_history = request(family_path(first) + "/history", teacher)
    assert family_history["items"]
    assert invite["code"] not in json.dumps(family_history)
    passed("immediate per-child revocation; sibling access and learning history preserved")

    (output / "parent-api-evidence.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f'API PASS: {len(result["checks"])} family scenarios. Evidence contains IDs and check names only.')


if __name__ == "__main__":
    try:
        main()
    except SmokeEnvironmentError as error:
        raise SystemExit(str(error)) from None
