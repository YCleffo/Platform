# EDUCATION_07 — Messaging & Communications Audit Matrix

## 1. Feature & Capability Matrix

| Area | Component / Endpoint | Status | Reused Existing | Implementation Details / Gaps |
|------|----------------------|--------|-----------------|-------------------------------|
| **Teacher ↔ Teacher Chat** | `GET /api/education/threads`<br>`POST /api/education/threads`<br>`GET /api/education/threads/{id}/messages`<br>`POST /api/education/threads/{id}/messages`<br>`POST /api/education/threads/{id}/read` | **EXISTS** | `education.threads`<br>`education.thread_members`<br>`education.messages`<br>`education.notifications` | Fully functional in backend. UI needs full redesign into modern two-pane messenger with search, unread badges, avatars, and timestamps. |
| **Teacher ↔ Student Chat** | `GET /api/education/contacts`<br>`POST /api/education/threads` (student target)<br>`GET /api/education/student/threads`<br>`POST /api/education/student/threads/{id}/messages` | **MISSING** | Reuses thread storage & notifications | Backend previously threw 403 `recipient_unavailable` (only checked `education.teachers`). Must allow teacher to message students in their enrolled groups, and student to message teachers of their groups. Must forbid student-student communication. |
| **Teacher ↔ Verified Parent Chat** | `GET /api/education/parent/children/{id}/threads`<br>`POST /api/education/parent/children/{id}/threads`<br>`POST /api/education/parent/children/{id}/threads/{id}/messages` | **MISSING** | `education.student_guardians`<br>`education.parent_accounts`<br>`ParentScope` | Parent has no message routes in `parent-routes.php`. Must enforce `status='confirmed'` guardian relationship with active child. Immediate revocation if parent-child link is revoked. |
| **Messenger Workspace (Desktop)** | `apps/frontend/src/modules/education/MessagesView.vue`<br>`apps/frontend/src/modules/education/ThreadView.vue` | **PARTIAL** | Shared design tokens, AppInput, AppButton, AppSkeleton | Currently separate single pages (`/messages` list and `/messages/:id` detail). Needs unified desktop two-pane layout (conversations left, active chat right, search, avatars, partner context). |
| **Messenger Workspace (Mobile)** | `apps/frontend/src/modules/education/` | **PARTIAL** | Responsive nav, AppBottomNav | Needs adaptive view: full-screen conversation list, smooth drill-down to active chat, sticky top header with back button, pinned input above keyboard, safe area insets. |
| **Student Messages Workspace** | `apps/frontend/src/modules/education/student/` | **MISSING** | `StudentLayout.vue`, `useEducationWorkspace` | Student portal currently has Home, Diary, Homework, Progress, Documents, but no Messages tab. |
| **Parent Messages Workspace** | `apps/frontend/src/modules/education/parent/` | **MISSING** | `ParentLayout.vue`, `useParentWorkspace` | Parent portal has Home, Schedule, Diary, Homework, Progress, Notifications, but no Messages tab in the child context. |
| **Read Cursor & Polling** | `last_read_message_id`<br>`last_read_at` | **EXISTS** | `002_message_read_cursor.sql` | Supported in DB and store. Needs UI polling with automatic pause when tab is hidden (`document.hidden`), cursor-based fetch, and optimistic UI update. |
| **Homework Creation from Lesson** | `HomeworkCreateView.vue`<br>`LessonView.vue` | **PARTIAL** | `HomeworkService.php`<br>`010_homework.sql` | Backend supports `groupId`, `lessonId`, `studentIds`. Frontend needs pre-filling of lesson title, group, and students when opened from lesson. |
| **Homework Review Queue & Overdue Badges** | `HomeworkListView.vue`<br>`HomeworkDetailView.vue` | **PARTIAL** | `HomeworkService.php` | Status filters (ALL, DRAFT, PUBLISHED, CLOSED) exist, but need clear overdue indicators, review submission badges, and quick return-with-comment workflow. |
| **Student Homework & S3 Attachments** | `StudentHomeworkView.vue`<br>`HomeworkFiles.vue` | **EXISTS** | `HomeworkFileService.php`<br>SeaweedFS S3 transfers | Fully functional in backend. UI requires seamless draft saving and clear submission feedback. |
| **Unified Education Notifications** | `GET /api/education/notifications`<br>`NotificationsView.vue` | **PARTIAL** | `education.notifications` | Notifications recorded for messages and homework. Needs click-through to open active chat or homework detail directly. |

---

## 2. Access Control & Authorization Invariants

1. **Teacher Scope:**
   - Can start threads with:
     a) Other teachers in the same organization.
     b) Enrolled students in groups created/owned by the teacher.
     c) Confirmed guardians (`student_guardians.status = 'confirmed'`) of those enrolled students.
   - Cannot start threads with students from other organizations or other teachers' unlinked groups.

2. **Student Scope:**
   - Can view and reply only to threads where the student is a member (`thread_members`).
   - Can initiate threads *only* with the teachers who teach their active groups.
   - Cannot search or message other students.

3. **Parent Scope:**
   - Scoped strictly to the selected child (`studentId`).
   - Can view and reply only to threads concerning that child.
   - Can initiate threads *only* with teachers who teach that child.
   - Immediate revocation: if `student_guardians` is revoked, `parentScope` immediately denies access with 403/404, invalidating cached state.
   - Cannot message other parents or students.
