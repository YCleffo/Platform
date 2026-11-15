# Isolated local Education

This environment is opt-in. It uses the standalone `compose.education-local.yaml`, project `platform-education-local`, a dedicated PostgreSQL volume, separate Redis, isolated files and API port 8088. It never combines with the working Compose file and never seeds or resets the working organization. Native Windows operations are not part of this environment.

The existing `platform-backend:latest` development image, `postgres:18` and `redis:8-alpine` must be available. The backend image can be built explicitly with `docker build --target dev -t platform-backend:latest apps/backend`; this does not start or change a working database. Existing Composer and frontend dependencies are reused.

From `C:\Platform`:

```powershell
python scripts/dev/education-local.py start
```

The launcher generates local random credentials/keys under ignored `runtime/education-local`, applies migrations with checksum verification and invokes the guarded, idempotent curriculum, homework and parent fixtures. Accounts, groups and subsequent user edits survive reruns while the test volume exists. No seed operation deletes a user's records. Redis is independent and is not flushed by the seed. The fictional primary teacher receives the additional `education.family_verifier` role; other teachers do not. Existing family records remain pending until explicitly confirmed.

Open `runtime/education-local/data/access.json` locally for the generated teacher, colleague and outsider credentials; the student's credentials are under `curriculum.studentAccount`. Do not publish this file. Seed data includes two groups, four students created through the Core application port, three teachers in two organizations, a program with two modules/four catalog lessons, four actual sessions, saved attendance/results and progress, a private lesson material, an activated student account and two explicitly offline test computers. Test computers never claim native Windows readiness.

Start the frontend in another PowerShell:

```powershell
Set-Location C:\Platform\apps\frontend
$env:VITE_EDUCATION_ENABLED = '1'
$env:VITE_CORE_PROXY_TARGET = 'http://127.0.0.1:8088'
npm run dev -- --host 127.0.0.1 --port 5178 --strictPort
```

Open `http://127.0.0.1:5178/education/login`. Sign in with the local teacher. Create a group, add a new or existing Core person, create a program/module/lesson, assign the program to the group and schedule a linked lesson. Once the scheduled start has passed, mark attendance, complete the session, save its report/results and open group/student progress. The colleague sees only the explicitly shared group. The outsider's organization demonstrates the initial empty state. The student's separate Core login opens their own cabinet and progress.

Focused automated checks:

```powershell
# Proves the actual backend and frontend proxy nonce before any browser mutation:
node scripts/dev/education-smoke.mjs
node scripts/dev/education-contract-smoke.mjs

# Preserves old records while applying 008/009 to a newly named isolated database:
docker exec platform-education-local-backend-1 php /education-tools/verify-upgrade.php

# Actual service/database tests; each test rolls back its records:
docker exec platform-education-local-backend-1 php vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/Foundation/CurriculumFoundationTest.php tests/Foundation/StudentPortalTest.php
```

Smoke artifacts and measurements are written to ignored `runtime/education-local/smoke`. The script checks four widths, two themes, real Core login, new learning workflows, private file access and student isolation. It creates new test records only after both direct API and UI proxy prove the same isolated nonce. It is not the repository E2E suite.

`--baseline` measures the separately started Git HEAD frontend at port 5179, using the same isolated API. That optional frontend must be exported into its own directory with the same dependencies; it is never copied over current source files. Calendar shell readiness and Today content readiness have different timings, so the report compares Today content readiness only.

```powershell
python scripts/dev/education-local.py seed   # idempotent; does not reset edited data
python scripts/dev/education-local.py status
python scripts/dev/education-local.py stop   # keeps containers, files and volumes
```

Parent checks use the same guarded isolated runtime:

```powershell
docker exec platform-education-local-backend-1 php vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/Foundation/ParentAccessTest.php
php apps/backend/vendor/bin/phpunit --no-configuration --bootstrap apps/backend/tests/bootstrap.php apps/backend/tests/Foundation/ParentContractTest.php
python scripts/dev/education/parent-api-smoke.py
docker exec platform-education-local-backend-1 php /education-tools/parent-lock-smoke.php
node scripts/dev/education/parent-contract-smoke.mjs
```

Run the native contract test from the repository root: it reads `contracts/` outside the backend container mount. The HTTP smoke preserves its fictional login only in ignored `runtime/education05/local-parent-access.json`; evidence files contain check names and IDs. The two-process lock check restores its fictional sibling link after proving concurrent revocation. These commands do not run a browser or the full E2E suite. After explicitly removing the disposable test volume, `start` recreates the fixtures; the HTTP smoke replaces credentials associated with the old fictional Person.

The Windows VM scripts, native provider, HTTPS guest trust and recovery matrix are documented in `apps/agent/VM_ACCEPTANCE.md`. Builds and a test-session adapter do not prove real Windows sign-in.
