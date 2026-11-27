# PLATFORM_16_C — Education Parent Cabinet Finalization Acceptance Report

**VERDICT: READY**

**Date:** 2026-11-27  
**Scope:** Finalization of the Education Parent Cabinet (`ParentLayout`, `ParentSectionView`, `ParentAnalyticsPanel`, `ParentHomeworkList`, `ParentReadService`, `EducationDashboardProvider`, `EducationChatContextPolicy`, `EducationMediaAccessAuthorizer`) on top of completed PLATFORM_13–15 subsystems (Identity/ACL, Activity Engine, Assessment Studio, Universal Messenger, Media Foundation, Workspace Dashboard, Notifications, Realtime, Design System).

---

## 1. Implemented Changes

### 1.1 Child Context, ACL & Privacy Hardening
- **[EducationMediaAccessAuthorizer.php](file:///C:/Platform/apps/backend/src/Education/Infrastructure/EducationMediaAccessAuthorizer.php)**:
  - Hardened parent media authorization (`canRead`) to require an active, non-revoked `education.parent_accounts` record (`pa.revoked_at IS NULL`) alongside `education.student_guardians` (`status = 'confirmed'`) and matching `organization_id`.
- **[EducationChatContextPolicy.php](file:///C:/Platform/apps/backend/src/Education/Infrastructure/EducationChatContextPolicy.php)**:
  - Extended parent allowed contacts in Universal Messenger to include organization administration (`core.admin` with active membership in the same organization) in addition to teachers of active groups of the parent's confirmed children, without exposing unrelated students, other families, or student personal chats.
- **[ParentSectionView.vue](file:///C:/Platform/apps/frontend/src/modules/education/parent/ParentSectionView.vue)**:
  - Keyed `<UniversalMessenger>` by `${workspace.child.organizationId}:${workspace.child.id}` alongside `<RouterView>` keying in [ParentLayout.vue](file:///C:/Platform/apps/frontend/src/modules/education/parent/ParentLayout.vue) to guarantee zero stale chat state when switching children.

### 1.2 Shared Workspace Dashboard & Real Child Projections
- **[DashboardContext.php](file:///C:/Platform/apps/backend/src/Workspace/Application/DashboardContext.php)**, **[WorkspaceDashboardService.php](file:///C:/Platform/apps/backend/src/Workspace/Application/WorkspaceDashboardService.php)**, **[WorkspaceAction.php](file:///C:/Platform/apps/backend/src/Workspace/Api/WorkspaceAction.php)**:
  - Added optional `studentId` parameter to `GET /api/workspace/dashboard`.
- **[EducationDashboardProvider.php](file:///C:/Platform/apps/backend/src/Workspace/Infrastructure/Dashboard/EducationDashboardProvider.php)**:
  - Verified `studentId` server-side against `education.parent_accounts` (`revoked_at IS NULL`) and `education.student_guardians` (`status = 'confirmed'`), returning empty widget data if spoofed or revoked.
  - Scoped `upcomingLessons` and `deadlines` to the verified child and added `upcomingAssessments` (from `activity.assignments`) and `activityResults` (from `activity.attempts` where `results_published_at IS NOT NULL`).
  - Fixed PostgreSQL `SELECT DISTINCT ... ORDER BY` alias ordering (`ORDER BY "startsAt"`, `ORDER BY "dueAt"`).
- **[workspaceApi.ts](file:///C:/Platform/apps/frontend/src/shared/api/workspaceApi.ts)**:
  - Extended `workspaceApi.getDashboard` to accept `{ studentId, signal }` while remaining backwards-compatible with existing `AbortSignal` callers.
- **[ParentSectionView.vue](file:///C:/Platform/apps/frontend/src/modules/education/parent/ParentSectionView.vue)** & **[ParentAnalyticsPanel.vue](file:///C:/Platform/apps/frontend/src/modules/education/parent/ParentAnalyticsPanel.vue)**:
  - Integrated `workspaceApi.getDashboard(child.organizationId, { studentId: child.id })` into the Parent Home dashboard.
  - Added **Upcoming Tests & Assessments** (`upcomingAssessments`), **Published Activity & Test Results** (`activityResults`), and **Recent Notifications** (`notifications.unread` widget + unread count).
  - Removed synthetic `100%` attendance when `attendance.total === 0` (now displays `—` and `0%` bar width).
  - Added `hide-debts` on the Progress tab so it does not display a misleading "No homework debts" banner when homework items are not loaded in that view.
  - Reused canonical `resultStatusToScore` from [journal.ts](file:///C:/Platform/apps/frontend/src/modules/education/journal.ts).

### 1.3 Academic Information & Study Materials
- **[ParentReadService.php](file:///C:/Platform/apps/backend/src/Education/Application/ParentReadService.php)**, **[ParentAction.php](file:///C:/Platform/apps/backend/src/Education/Api/ParentAction.php)**, **[parent-routes.php](file:///C:/Platform/apps/backend/config/common/parent-routes.php)**:
  - Added `upcomingAssessments` and `activityResults` to `ParentReadService::home()`.
  - Added `materials` (`education.homework_files` with `purpose = 'material'`) to `ParentReadService::homework()`.
  - Added read-only `GET /api/education/parent/children/{studentId}/homework/files/{id}` (`homeworkMaterialDownload`) with `parentScope` + `allowed($scope, 'homework', $assignmentId)` + `purpose = 'material'` enforcement.
- **[ParentHomeworkList.vue](file:///C:/Platform/apps/frontend/src/modules/education/parent/ParentHomeworkList.vue)**, **[api.ts](file:///C:/Platform/apps/frontend/src/modules/education/parent/api.ts)**, **[i18n.ts](file:///C:/Platform/apps/frontend/src/modules/education/i18n.ts)**:
  - Rendered homework study materials with authenticated download (`parentApi.downloadHomeworkMaterial`) and added RU/EN translations for all new parent UI labels.

---

## 2. Verification & Executed Checks

1. **Frontend Typecheck (`vue-tsc --noEmit` + `tsc --noEmit`)**:
   - **0 errors**.
2. **Frontend ESLint**:
   - `npx eslint src/modules/education/parent/ src/modules/education/i18n.ts src/shared/api/workspaceApi.ts`: **0 errors, 0 warnings**.
3. **Frontend Unit & Component Tests (`vitest`)**:
   - `npx vitest run src/modules/education/parent/ src/modules/workspace/WorkspaceViews.spec.ts`: **8 files, 42/42 tests passed** (`ParentContexts.qa.spec.ts`, `ParentAnalyticsPanel.spec.ts`, `ParentPrivacy.qa.spec.ts`, `ParentLayout.spec.ts`, `ParentExistingLogin.spec.ts`, `ParentViews.spec.ts`, `ParentActivities.spec.ts`, `WorkspaceViews.spec.ts`).
4. **Backend Workspace PHPUnit Tests**:
   - `docker exec platform-backend vendor/bin/phpunit tests/Foundation/Platform12WorkspaceTest.php`: **8/8 tests, 105 assertions passed**.
5. **Real-Browser Local E2E Acceptance (`apps/frontend/scripts/e2e-platform16-c-parent.mjs`)**:
   - Executed against live backend (`http://127.0.0.1:8083`) and frontend (`http://127.0.0.1:5173`) using isolated fixture data with a multi-child confirmed parent (`parent1` linked to `student1` and `student3`), an unrelated child (`student2`), an unconfirmed parent (`unconfirmedParent`), and a second organization (`orgB`).
   - Verified:
     - **Spoofed `childId`** (`GET /api/education/parent/children/{student2}/home`) → `404`.
     - **Cross-org isolation** (`GET /api/education/parent/children/{student1}/home` with `orgB` header) → `403`/`404`.
     - **Pending/unconfirmed guardian** → `403`/`404`.
     - **Parent write-protection** (`PUT /api/education/lessons/{id}/results/{student1}`) → `403`.
     - **Workspace Dashboard ACL** (`GET /api/workspace/dashboard?organizationId={orgA}&studentId=...`): returns scoped `upcomingAssessments` for `student1`, returns `0` items for spoofed `student2`.
     - **Multi-child switching** in UI: `student1` → `student3` clears `student1` feedback/grades with zero cross-child data leakage.
     - **Full navigation**: Home Dashboard → Diary → Homework → Progress → Universal Messenger → Notifications → Mobile (`390x844`) & Dark Theme.
     - **Relationship revocation**: `POST /api/education/students/{student3}/guardians/{parent1}/revoke` immediately blocks subsequent access (`403`/`404`).
     - Console errors: **0**, Server 5xx errors: **0**.

---

## 3. Screenshots

- Parent Home Dashboard (WorkspaceDashboard + Child Overview): [p16c-parent-home.png](file:///C:/Platform/docs/audits/screenshots/p16c-parent-home.png)
- Child Switcher (No Cross-Child Data Leakage): [p16c-parent-switch-child.png](file:///C:/Platform/docs/audits/screenshots/p16c-parent-switch-child.png)
- Diary (Lessons, Attendance, Grades, Teacher Comments): [p16c-parent-diary.png](file:///C:/Platform/docs/audits/screenshots/p16c-parent-diary.png)
- Homework & Statuses: [p16c-parent-homework.png](file:///C:/Platform/docs/audits/screenshots/p16c-parent-homework.png)
- Progress & Activity Results: [p16c-parent-progress.png](file:///C:/Platform/docs/audits/screenshots/p16c-parent-progress.png)
- Universal Messenger: [p16c-parent-messages.png](file:///C:/Platform/docs/audits/screenshots/p16c-parent-messages.png)
- Notifications: [p16c-parent-notifications.png](file:///C:/Platform/docs/audits/screenshots/p16c-parent-notifications.png)
- Mobile (`390x844`) & Dark Theme: [p16c-parent-mobile-dark.png](file:///C:/Platform/docs/audits/screenshots/p16c-parent-mobile-dark.png)

---

## 4. Limitations & Boundaries

- `ParentAccessTest.php` / `ParentContractTest.php` require the dedicated isolated test PostgreSQL database (`platform_test` with `TEST_ISOLATED_RUNTIME=1`), which is not mounted in the local `platform-backend` dev container; instead, all parent ACL, multi-child, cross-org, write-protection, and revocation flows were verified end-to-end against the live backend via `e2e-platform16-c-parent.mjs`, alongside `Platform12WorkspaceTest.php` and 42 frontend unit/component tests.
- Global full-suite CI was not run per task boundaries.
- `PLATFORM_17` has not been started.
