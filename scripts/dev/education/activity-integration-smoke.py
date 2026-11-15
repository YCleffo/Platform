"""Focused Homework -> Logic -> accepted -> parent progress, on the guarded EDUCATION_06 fixture only."""
from __future__ import annotations

import argparse
import datetime
import json
from pathlib import Path
import secrets
import subprocess
import uuid

from importlib.util import spec_from_file_location, module_from_spec
import sys


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--data-dir', type=Path, required=True)
    parser.add_argument('--evidence', type=Path, required=True)
    args = parser.parse_args()
    subprocess.run(['docker', 'exec', 'platform-education06-local-backend-1', 'php', '-r',
                    "require '/education-tools/guard.php'; educationFixtureDatabase();"], check=True, capture_output=True)
    spec = spec_from_file_location('homework_client', Path(__file__).with_name('homework-api-smoke.py'))
    assert spec and spec.loader
    helper = module_from_spec(spec); sys.modules[spec.name] = helper; spec.loader.exec_module(helper)
    fixture = json.loads((args.data_dir / 'access.json').read_text(encoding='utf-8'))
    demo = json.loads((args.data_dir / 'education04-demo.json').read_text(encoding='utf-8'))
    client = helper.ApiClient('http://127.0.0.1:8088', fixture['organizationId'])
    req = client.request
    teacher = client.login(fixture['accounts']['teacher'])
    learner = client.login(demo['studentAccounts'][0])
    child = demo['students'][0]['id']; guardian = demo['parents'][0]['personId']
    family = f'/api/education/students/{child}/guardians/{guardian}'
    rows = req(f'/api/education/students/{child}/guardians', teacher)['items']
    row = next(item for item in rows if item['personId'] == guardian)
    if row['status'] != 'confirmed': req(family + '/confirm', teacher, 'POST', {})
    invite = req(family + '/invite', teacher, 'POST', {}, (200, 201))
    credentials_path = args.data_dir / 'education06-parent-access.json'
    if credentials_path.exists():
        credentials = json.loads(credentials_path.read_text(encoding='utf-8'))
        parent = client.login(credentials)
        req('/api/education/parent/activate', parent, 'POST', {'code': invite['code']})
    else:
        credentials = {'email': 'parent-education06-' + uuid.uuid4().hex + '@example.test', 'password': secrets.token_urlsafe(24)}
        req('/api/education/parent/register', method='POST', body={'code': invite['code'], **credentials}, expected=(200, 201))
        credentials_path.write_text(json.dumps(credentials), encoding='utf-8')
        parent = client.login(credentials)
    detail = req('/api/education/homework', teacher, 'POST', {
        'title': 'EDUCATION_06 Logic integration', 'description': 'Isolated fixture', 'instructions': 'Complete five rounds.',
        'type': 'activity', 'groupId': None, 'studentIds': [child], 'dueAt': None, 'allowLate': False,
        'activityCode': 'platform-logic', 'activityConfig': {'language': 'en', 'difficulty': 2, 'roundCount': 5, 'minAccuracy': 0},
        'maxAttempts': 3, 'attemptPolicy': 'best',
    }, (200, 201))
    assignment = detail['assignment']
    req(f"/api/education/homework/{assignment['id']}/publish", teacher, 'POST', {'version': assignment['version']})
    path = f"/api/education/student/homework/{assignment['id']}"
    start = req(path + '/activity/start', learner, 'POST', {}, (200, 201))
    answers = [{'roundId': r['id'], 'optionId': r['options'][0]['id']} for r in start['exercise']['rounds']]
    result = req(f"/api/education/student/attempts/{start['attempt']['id']}/finish", learner, 'PUT', {'answers': answers})
    answer = req(path, learner)
    assert answer['submission']['activityResult']['attemptId'] == result['attemptId']
    submitted = req(path + '/submit', learner, 'POST', {'version': answer['submission']['version'], 'idempotencyKey': str(uuid.uuid4())})
    sub = submitted['submission']
    req(f"/api/education/homework/submissions/{sub['id']}/review", teacher, 'POST', {
        'version': sub['version'], 'idempotencyKey': str(uuid.uuid4()), 'decision': 'accepted', 'resultStatus': 'mastered',
    })
    progress = req(f'/api/education/parent/children/{child}/progress', parent)
    assert progress['activityProgress']['logic']['attempts'] >= 1
    assert progress['activityProgress']['completedAssignments'] >= 1
    assert any(a['code'] == 'first-homework' and a['unlockedAt'] for a in progress['achievements'])
    assert any(a['code'] == 'first-logic' and a['unlockedAt'] for a in progress['achievements'])
    private = json.dumps(progress)
    assert all(key not in private for key in ['correctOptionId', 'exercise_payload', 'clientState', 'explanation'])
    req('/api/education/student/activities/platform-logic/attempts', parent, 'POST', {'language': 'en'}, expected=(403, 404))
    notes = req(f'/api/education/parent/children/{child}/notifications', parent)['items']
    assert {'parent_activity_finished', 'parent_achievement_unlocked'}.issubset({n['kind'] for n in notes})
    checks = ['Logic Homework assignment/start/link/submit/review', 'confirmed-source homework achievement',
              'ParentScope games/awards/completed assignments', 'no private answers in parent DTO', 'parent cannot play', 'parent notifications']
    args.evidence.parent.mkdir(parents=True, exist_ok=True)
    args.evidence.write_text(json.dumps({'contract': 'EDUCATION_06', 'checks': checks,
        'checkedAt': datetime.datetime.now(datetime.timezone.utc).isoformat()}, indent=2), encoding='utf-8')
    print('Integration API PASS: Homework Logic, accepted award, parent progress, read-only scope and notifications.')


if __name__ == '__main__':
    main()
