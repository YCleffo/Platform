"""Bounded EDUCATION_06 HTTP contract/race checks, only against the guarded local fixture."""

from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import importlib.util
import json
from pathlib import Path
import sys
from typing import Any, TypeAlias

from smoke_runtime import DEFAULT_RUNTIME, loopback, read_proof, verify_fixture, verify_http_origin

JsonValue: TypeAlias = (
    str | int | float | bool | None | list["JsonValue"] | dict[str, "JsonValue"]
)

def load_homework_client() -> Any:
    path = Path(__file__).with_name("homework-api-smoke.py")
    spec = importlib.util.spec_from_file_location("education_homework_smoke", path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def no_solutions(value: JsonValue) -> None:
    if isinstance(value, dict):
        assert not {"correctOptionId", "explanation", "exercise_private", "solution", "correctAnswer"}.intersection(value)
        for item in value.values():
            no_solutions(item)
    elif isinstance(value, list):
        for item in value:
            no_solutions(item)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime", type=Path, default=DEFAULT_RUNTIME)
    parser.add_argument("--base", help="Optional Vite loopback origin serving this fixture")
    parser.add_argument("--data-dir", type=Path)
    parser.add_argument("--evidence", type=Path)
    args = parser.parse_args()
    runtime = verify_fixture(args.runtime)
    origin = runtime.api_origin
    if args.base is not None:
        parsed = loopback(args.base, origin_only=True)
        origin = f"{parsed.scheme}://{parsed.netloc}"
        verify_http_origin(origin, read_proof(args.runtime))
    data = args.data_dir or args.runtime / "data"
    args.evidence = args.evidence or args.runtime / "smoke/activity-api-evidence.json"
    helper = load_homework_client()
    fixture = helper.decode_object((data / "access.json").read_text(encoding="utf-8"), "fixture")
    demo = helper.decode_object((data / "education04-demo.json").read_text(encoding="utf-8"), "demo")
    client = helper.ApiClient(origin, helper.string_field(fixture, "organizationId"))
    student = client.login(demo["studentAccounts"][0])
    peer = client.login(demo["studentAccounts"][1])
    request = client.request
    prefix = "/api/education/student"
    catalog = request(prefix + "/activities", student)
    assert {"platform-typing", "platform-logic"}.issubset({item["code"] for item in catalog["items"]})

    typing = request(prefix + "/activities/platform-typing/attempts", student, "POST",
                     {"durationSeconds": 15, "language": "ru"}, expected=(200, 201))
    attempt = typing["attempt"]["id"]
    typed = typing["exercise"]["text"][:25]
    finish_path = prefix + f"/attempts/{attempt}/finish"
    finish = {"typed": typed, "durationSeconds": 15}
    request(finish_path, peer, "PUT", finish, expected=(403, 404))
    request(finish_path, student, "PUT", finish, expected=(403, 404), org=fixture["otherOrganizationId"])
    request(finish_path, student, "PUT", {**finish, "score": 100000}, expected=422)
    with ThreadPoolExecutor(max_workers=2) as pool:
        futures = [pool.submit(request, finish_path, student, "PUT", finish) for _ in range(2)]
        results = [future.result() for future in futures]
    assert results[0] == results[1], "Concurrent identical finishes must return one immutable result."
    assert results[0]["correctChars"] == 25 and results[0]["accuracy"] == 100
    request(finish_path, student, "PUT", {**finish, "typed": typed + "changed"}, expected=409)
    achievements_before = request(prefix + "/achievements", student)
    request(finish_path, student, "PUT", finish)
    assert request(prefix + "/achievements", student) == achievements_before

    logic = request(prefix + "/activities/platform-logic/attempts", student, "POST",
                    {"language": "en", "difficulty": 1, "roundCount": 5}, expected=(200, 201))
    no_solutions(logic)
    logic_id = logic["attempt"]["id"]
    rounds = logic["exercise"]["rounds"]
    assert len(rounds) == 5 and all(len(item["options"]) >= 2 for item in rounds)
    answers = [{"roundId": item["id"], "optionId": item["options"][0]["id"]} for item in rounds]
    state_path = prefix + f"/attempts/{logic_id}/state"
    request(state_path, student, "PUT", {"version": 0, "state": {"answers": answers[:1], "currentRound": 1}})
    loaded = request(prefix + f"/attempts/{logic_id}", student)
    no_solutions(loaded)
    assert loaded["attempt"]["state"]["answers"] == answers[:1]
    assert loaded["exercise"] == logic["exercise"]
    request(state_path, student, "PUT", {"version": 0, "state": {"answers": []}}, expected=409)
    request(prefix + f"/attempts/{logic_id}/finish", student, "PUT", {"answers": answers, "score": 999}, expected=422)
    logic_finish = prefix + f"/attempts/{logic_id}/finish"
    with ThreadPoolExecutor(max_workers=2) as pool:
        futures = [pool.submit(request, logic_finish, student, "PUT", {"answers": answers}) for _ in range(2)]
        logic_results = [future.result() for future in futures]
    assert logic_results[0] == logic_results[1]
    result = logic_results[0]
    assert result["correctAnswers"] + result["errors"] == 5
    assert result["accuracy"] == result["correctAnswers"] / 5 * 100
    assert all(item["explanation"] and "correctOptionId" in item for item in result["rounds"])
    history = request(prefix + "/attempts", student)
    ids = [item["id"] for item in history["items"]]
    assert ids.count(attempt) == 1 and ids.count(logic_id) == 1
    awards = request(prefix + "/achievements", student)
    unlocks = awards.get("items", awards.get("unlocks", []))
    active = [item for item in unlocks if item.get("unlockedAt") and not item.get("revokedAt")]
    keys = [(item.get("code"), item.get("ruleVersion")) for item in active]
    assert len(keys) == len(set(keys)), "Concurrent completion must not duplicate achievement unlocks."
    request(prefix + "/activity-progress", student)
    # Keep identity, tokens, answer text, private snapshots and raw DTOs out of evidence.
    evidence = {"contract": "EDUCATION_06", "checks": {
        "catalog_two_games": True, "typing_unicode_legacy": True, "strict_server_metrics": True,
        "peer_and_organization_isolation": True, "typing_concurrent_same_payload": True,
        "changed_payload_conflict": True, "logic_no_solution_leak": True,
        "logic_state_resume_and_conflict": True, "logic_concurrent_same_payload": True,
        "server_solution_explanations": True, "history_single_result": True,
        "achievement_replay_and_unique": True, "activity_progress": True,
    }, "typing": {"characters": 25, "wpm": results[0]["wpm"]},
        "logic": {"rounds": 5, "correct": result["correctAnswers"]}, "activeUnlockCount": len(active)}
    args.evidence.parent.mkdir(parents=True, exist_ok=True)
    args.evidence.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    print("API PASS: legacy Typing, Logic resume/server verification, tenant/peer isolation, concurrent finish and achievement replay; redacted evidence saved.")


if __name__ == "__main__":
    main()
