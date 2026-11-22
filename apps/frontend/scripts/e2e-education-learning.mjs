import assert from 'node:assert/strict';
import { navigateClientSide } from './e2e-support.mjs';

const startTraining = /^(Начать тренировку|Start training)$/;
const finishTraining = /^(Завершить|Finish)$/;
const trainingResult = /^(Результат тренировки|Training result)$/;
const evidenceAttachment = /^homework-example\.txt ·/;

async function afterMutation(page, method, path, action, status = 200) {
  const [response] = await Promise.all([
    page.waitForResponse(
      (response) =>
        response.request().method() === method &&
        (typeof path === 'string'
          ? new URL(response.url()).pathname === path
          : path.test(new URL(response.url()).pathname)),
    ),
    action(),
  ]);
  const payload = await response.json();
  assert.equal(
    response.status(),
    status,
    `${method} ${path} failed with ${payload.error?.code ?? 'an unexpected response'}`,
  );
  return payload.data;
}

async function confirm(page, name) {
  await page.getByRole('button', { name }).click();
  await page.getByRole('dialog').getByRole('button', { name }).click();
}

async function verifyHomework(teacher, student, first) {
  const title = 'Практика: дроби и единицы';
  const instructions = 'Объясните решение и приложите пример.';
  const text = 'Сначала я привожу дроби к общему знаменателю.';
  const feedback = 'Ход решения проверен. Знаменатель выбран верно.';
  const bytes = Buffer.from('Disposable homework evidence\n');
  const fileName = 'homework-example.txt';
  const groupId = first.groupId ?? first.lesson.groupId;
  const teacherPage = teacher.page;
  const studentPage = student.page;

  // A contextual homework-create link preselects the group in the real composer.
  await navigateClientSide(teacherPage, `/homework/new?groupId=${groupId}`);
  await teacherPage.locator('#homework-title').fill(title);
  await teacherPage.locator('form textarea[required]').fill(instructions);
  const created = await afterMutation(
    teacherPage,
    'POST',
    '/api/education/homework',
    () => teacherPage.locator('form button[type="submit"]').click(),
    201,
  );
  const assignment = created.assignment.id;
  await teacherPage.waitForURL((url) => url.pathname === `/homework/${assignment}`);
  assert.equal(created.assignment.groupId, groupId);
  assert.equal(created.assignment.status, 'DRAFT');
  await afterMutation(teacherPage, 'POST', `/api/education/homework/${assignment}/publish`, () =>
    confirm(teacherPage, /^(Опубликовать|Publish)$/),
  );
  assert.equal((await teacher.api(`/homework/${assignment}`)).assignment.status, 'PUBLISHED');

  await navigateClientSide(studentPage, '/student/homework');
  await studentPage.getByRole('link', { name: title, exact: true }).click();
  await studentPage.locator('textarea').fill(text);
  await afterMutation(
    studentPage,
    'PUT',
    `/api/education/student/homework/${assignment}/draft`,
    () => studentPage.getByRole('button', { name: /^(Сохранить черновик|Save draft)$/ }).click(),
  );
  await student.reopen();
  await studentPage.waitForFunction(
    (value) => document.querySelector('textarea')?.value === value,
    text,
  );
  assert.equal((await student.api(`/student/homework/${assignment}`)).submission.text, text);

  const file = await afterMutation(
    studentPage,
    'POST',
    /\/api\/education\/file-transfers\/[^/]+\/complete$/,
    () =>
      studentPage
        .locator('input[type="file"]')
        .setInputFiles({ name: fileName, mimeType: 'text/plain', buffer: bytes }),
  );
  assert.ok(file, 'The attachment was not confirmed.');
  await studentPage.getByRole('button', { name: evidenceAttachment }).waitFor();
  const submitted = await afterMutation(
    studentPage,
    'POST',
    `/api/education/student/homework/${assignment}/submit`,
    () => confirm(studentPage, /^(Отправить работу|Submit work)$/),
  );
  assert.equal(submitted.submission.state, 'SUBMITTED');
  const roster = await teacher.api(`/homework/${assignment}/submissions`);
  const submission = roster.items.find((row) => row.studentId === first.id)?.submissionId;
  assert.ok(submission, 'Published homework has no submission for the fixture student.');
  await teacher.reopen();
  await teacherPage.locator(`a[href="/homework/submissions/${submission}"]`).click();
  await teacherPage.getByRole('article').getByText(text, { exact: true }).waitFor();
  await teacherPage.getByRole('button', { name: evidenceAttachment }).first().waitFor();
  await teacherPage.locator('form textarea').fill(feedback);
  await afterMutation(
    teacherPage,
    'POST',
    `/api/education/homework/submissions/${submission}/review`,
    () => confirm(teacherPage, /^(Принять работу|Accept work)$/),
  );
  await student.reopen();
  await studentPage.getByText(feedback, { exact: true }).waitFor();
  const reviewed = await student.api(`/student/homework/${assignment}`);
  assert.equal(reviewed.submission.state, 'REVIEWED');
  assert.equal(reviewed.submission.currentVersion.text, text);
  assert.ok(reviewed.submission.currentVersion.files.some((file) => file.name === fileName));
  assert.ok(
    reviewed.submission.reviews.some(
      (review) => review.decision === 'accepted' && review.comment === feedback,
    ),
  );
  console.log(
    'Teacher homework publication, student draft, confirmed attachment, submission and teacher review verified through browser and API read-back.',
  );
}

async function assignLearningActivity(teacher, groupId, code) {
  const page = teacher.page;
  await navigateClientSide(page, `/groups/${groupId}/activities`);
  const form = page
    .locator('form')
    .filter({ has: page.locator('select') })
    .first();
  try {
    await form.locator('select').first().selectOption(code);
  } catch (error) {
    const visible = await page.locator('main [role="alert"]:visible').allTextContents();
    throw new Error(
      `Group activity form unavailable at ${new URL(page.url()).pathname}. ${visible.join(' ')}`,
      { cause: error },
    );
  }
  const assigned = await afterMutation(
    page,
    'POST',
    `/api/education/activities/${code}/assignments`,
    () => form.locator('button[type="submit"]').click(),
    201,
  );
  assert.equal(assigned.activityCode, code);
  assert.equal(assigned.groupId, groupId);
  await page.getByText('Активность назначена.', { exact: true }).waitFor();
  const assignments = page.locator('details').filter({
    has: page.locator('summary').getByText('Учебные назначения', { exact: true }),
  });
  await assignments.locator('summary').click();
  await assignments.locator('.edu-list__item strong')
    .filter({ hasText: assigned.activityName })
    .first()
    .waitFor();
  return assigned.id;
}

async function openLearningAssignment(student, code, assignment, path) {
  await navigateClientSide(student.page, path);
  await student.page.locator(`a[href="${path}?assignment=${assignment}"]`).click();
  const started = await afterMutation(
    student.page,
    'POST',
    `/api/education/student/activities/${code}/attempts`,
    () => student.page.getByRole('button', { name: startTraining }).click(),
    201,
  );
  assert.equal(started.attempt.assignmentId, assignment);
  return started;
}

async function verifyActivities(teacher, student, first) {
  const groupId = first.groupId ?? first.lesson.groupId;
  const page = student.page;
  const typing = await assignLearningActivity(teacher, groupId, 'platform-typing');
  const logic = await assignLearningActivity(teacher, groupId, 'platform-logic');
  const startedTyping = await openLearningAssignment(
    student,
    'platform-typing',
    typing,
    '/student/typing/platform-typing',
  );
  const typingId = startedTyping.attempt.id;
  const target = startedTyping.exercise.text.slice(0, 20);
  await page.locator('.edu-typing__input').fill(target);
  const checkpoint = page.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      new URL(response.url()).pathname === `/api/education/student/attempts/${typingId}/state`,
  );
  const typingResult = await afterMutation(
    page,
    'PUT',
    `/api/education/student/attempts/${typingId}/finish`,
    () => page.getByRole('button', { name: finishTraining }).click(),
  );
  assert.equal((await checkpoint).status(), 200);
  await page.getByRole('heading', { name: trainingResult }).waitFor();
  assert.equal(typingResult.correctChars, [...target].length);
  assert.equal(typingResult.accuracy, 100);
  const persistedTyping = await student.api(`/student/attempts/${typingId}`);
  assert.equal(persistedTyping.attempt.status, 'finished');
  assert.equal(persistedTyping.result.correctChars, typingResult.correctChars);

  const startedLogic = await openLearningAssignment(
    student,
    'platform-logic',
    logic,
    '/student/logic/platform-logic',
  );
  const logicId = startedLogic.attempt.id;
  const statePath = `/api/education/student/attempts/${logicId}/state`;
  const radio = () => page.locator('.edu-logic__options input[type="radio"]').first();
  const firstAnswer = await afterMutation(page, 'PUT', statePath, () => radio().check());
  assert.equal(firstAnswer.attempt.state.answers.length, 1);
  await page.waitForURL((url) => url.searchParams.get('attempt') === logicId);
  await student.reopen();
  await radio().waitFor();
  assert.equal(await radio().isChecked(), true, 'Logic answer did not survive reload.');
  const count = startedLogic.exercise.rounds.length;
  for (let round = 1; round < count; round++) {
    await afterMutation(page, 'PUT', statePath, () =>
      page.getByRole('button', { name: /^(Следующий раунд|Next round)$/ }).click(),
    );
    await afterMutation(page, 'PUT', statePath, () => radio().check());
  }
  const logicResult = await afterMutation(
    page,
    'PUT',
    `/api/education/student/attempts/${logicId}/finish`,
    () => page.getByRole('button', { name: finishTraining }).click(),
  );
  assert.equal(logicResult.totalRounds, count);
  await page.getByRole('heading', { name: trainingResult }).waitFor();
  const progress = await student.api('/student/activity-progress');
  assert.ok(progress.typing.history.some((entry) => entry.attemptId === typingId));
  assert.ok(progress.logic.history.some((entry) => entry.attemptId === logicId));
  const achievements = await student.api('/student/achievements');
  await navigateClientSide(page, '/student/progress');
  const history = page.locator('.edu-list__item');
  await history.filter({ hasText: 'Platform Typing' }).last().waitFor();
  await history.filter({ hasText: 'Platform Logic' }).last().waitFor();
  await navigateClientSide(page, '/student/achievements');
  for (const code of ['first-training', 'first-logic']) {
    const confirmed = achievements.items.find((item) => item.code === code && item.unlockedAt);
    assert.ok(confirmed, `Server did not confirm achievement ${code}.`);
    await page.locator('.edu-achievement--unlocked').getByText(confirmed.name, { exact: true }).waitFor();
  }
  await navigateClientSide(page, '/student/home');
  await page.getByRole('heading', { name: 'Личный рекорд' }).waitFor();
  console.log(
    'Teacher activity assignment, scoped Typing and Logic attempts, Logic resume, progress and backend achievements verified.',
  );
}

export async function verifyLearning({ teacher, student, first }) {
  await verifyHomework(teacher, student, first);
  await verifyActivities(teacher, student, first);
}
