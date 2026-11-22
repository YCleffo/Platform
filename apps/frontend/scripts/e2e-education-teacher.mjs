import assert from 'node:assert/strict';
import { navigateClientSide, selectDropdown } from './e2e-support.mjs';

function wallTime(instant, timezone) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(new Date(instant))
      .map(({ type, value }) => [type, value]),
  );
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

function waitForWrite(page, method, path) {
  return page.waitForResponse(
    (response) =>
      response.request().method() === method &&
      new URL(response.url()).pathname === `/api/education${path}`,
  );
}

async function saved(response) {
  assert.ok(response.ok(), `Education write returned HTTP ${response.status()}`);
  return (await response.json()).data;
}

export async function verifyEnrollmentToJournal({ page, api }, { otherOrganizationId } = {}) {
  const familyName = 'Новикова';
  const givenName = 'Вера';
  const title = 'Практика работы с таблицами';
  const comment = 'Самостоятельно построила таблицу и проверила формулы.';
  const submit = () => page.locator('form button[type=submit]').click();

  await navigateClientSide(page, '/groups/new');
  await page.locator('#education-create-title').fill('Группа · зачисление и журнал');
  const [groupResponse] = await Promise.all([waitForWrite(page, 'POST', '/groups'), submit()]);
  const group = await saved(groupResponse);
  const groupPath = `/groups/${group.id}`;
  const enroll = async () => {
    try {
      await page.locator(`a[href="/students/new?group=${group.id}"]`).click();
    } catch (error) {
      const message = await page.locator('main [role="alert"]').first().textContent({ timeout: 1500 }).catch(() => '');
      throw new Error(`Group enrollment link unavailable at ${new URL(page.url()).pathname}${message ? ` (${message.trim()})` : ''}.`, { cause: error });
    }
  };
  await page.waitForURL((url) => url.pathname === groupPath);
  await enroll();
  await page.locator('#student-family-name').fill(familyName);
  await page.locator('#student-given-name').fill(givenName);
  await page.locator('#student-birth-date').fill('2013-04-12');
  const [createdResponse] = await Promise.all([
    waitForWrite(page, 'POST', '/students/new'),
    submit(),
  ]);
  const student = await saved(createdResponse);
  assert.ok(student.id && student.personId);
  assert.notEqual(student.id, student.personId, 'Education Student and Core Person IDs must differ');

  const studentPath = `/students/${student.id}`;
  await page.waitForURL((url) => url.pathname === groupPath);
  const studentLink = () => page.locator(`a[href="${studentPath}"]`);
  await studentLink().getByText(new RegExp(givenName)).waitFor();
  let roster = (await api(`${groupPath}/students`)).items;
  assert.deepEqual(roster.map(({ id, personId }) => ({ id, personId })), [
    { id: student.id, personId: student.personId },
  ]);
  const card = await api(studentPath);
  assert.equal(card.personId, student.personId);
  assert.equal(card.account, null, 'Enrolling a person must not provision a Core account');
  assert.deepEqual(card.groups.map(({ id }) => id), [group.id]);

  await enroll();
  await page.getByRole('button', { name: 'Выбрать существующего', exact: true }).click();
  await selectDropdown(page, 'education-create-person', student.personId);
  const [enrolledResponse] = await Promise.all([
    waitForWrite(page, 'POST', '/students'),
    submit(),
  ]);
  const enrolled = await saved(enrolledResponse);
  assert.equal(enrolled.id, student.id);
  assert.equal(enrolled.personId, student.personId);
  await page.waitForURL((url) => url.pathname === groupPath);
  roster = (await api(`${groupPath}/students`)).items;
  assert.deepEqual(roster.map(({ id }) => id), [student.id]);
  const people = (await api('/people/candidates')).items;
  assert.deepEqual(
    people.filter(({ name }) => name.includes(familyName) && name.includes(givenName)).map(({ id }) => id),
    [student.personId],
    'Re-enrollment must reuse the existing Core Person',
  );
  await api(`/persons/${student.personId}`, { resource: 'core', status: 403 });
  if (otherOrganizationId) {
    await api(studentPath, { organizationId: otherOrganizationId, status: 403 });
  }

  const header = await api('/today/header');
  const start = Date.parse(header.now) - 10 * 60_000;
  await navigateClientSide(page, `/lessons/new?group=${group.id}`);
  await page.locator('#education-create-title').fill(title);
  await page.locator('#education-starts-at').fill(wallTime(start, header.timezone));
  await page.locator('#education-starts-at').press('Tab');
  await page.locator('#education-ends-at').fill(wallTime(start + 30 * 60_000, header.timezone));
  await page.locator('#education-ends-at').press('Tab');
  await page.locator('#education-location').fill('Каб. 7');
  const [lessonResponse] = await Promise.all([
    waitForWrite(page, 'POST', '/lessons'),
    submit(),
  ]);
  const lesson = await saved(lessonResponse);
  assert.equal(lesson.groupId, group.id);
  assert.equal(lesson.studentCount, 1);
  const lessonPath = `/lessons/${lesson.id}`;
  await page.waitForURL((url) => url.pathname === lessonPath);
  await page.getByRole('link', { name: 'Список', exact: true }).click();
  const [attendanceResponse] = await Promise.all([
    waitForWrite(page, 'PUT', `${lessonPath}/participants/${student.id}`),
    selectDropdown(page, `education-attendance-${student.id}`, 'present'),
  ]);
  await saved(attendanceResponse);
  const participants = (await api(`${lessonPath}/participants`)).items;
  assert.equal(participants.find(({ id }) => id === student.id)?.status, 'present');

  await page.getByRole('link', { name: 'Открыть занятие', exact: true }).click();
  await page.getByRole('button', { name: 'Завершить занятие', exact: true }).click();
  const [completeResponse] = await Promise.all([
    waitForWrite(page, 'POST', `${lessonPath}/complete`),
    page.getByRole('dialog').getByRole('button', { name: 'Завершить занятие', exact: true }).click(),
  ]);
  await saved(completeResponse);
  await page.getByText('Завершено', { exact: true }).waitFor();
  const completed = await api(lessonPath);
  assert.equal(completed.status, 'completed');
  assert.ok(completed.completedAt);

  await page.getByRole('link', { name: 'Результаты', exact: true }).click();
  const resultRow = page.locator('[data-result-student]').filter({ hasText: givenName });
  await selectDropdown(page, `education-result-${student.id}`, 'mastered');
  await resultRow.getByRole('textbox').fill(comment);
  const [resultResponse] = await Promise.all([
    waitForWrite(page, 'PUT', `${lessonPath}/results/${student.id}`),
    resultRow.getByRole('button', { name: 'Сохранить результат', exact: true }).click(),
  ]);
  const result = await saved(resultResponse);
  assert.equal(result.studentId, student.id);
  assert.equal(result.status, 'mastered');
  assert.equal(result.comment, comment);

  await page.locator(`a[href="${groupPath}"]`).click();
  await studentLink().click();
  await page.getByText(comment, { exact: true }).first().waitFor();
  await page.getByText('Аккаунт не активирован', { exact: true }).waitFor();
  const results = (await api(`${studentPath}/results`)).items;
  const studentResult = results.find(({ lessonId }) => lessonId === lesson.id);
  assert.equal(studentResult?.status, 'mastered');
  assert.equal(studentResult.comment, comment);
  await page.getByRole('link', { name: 'Журнал', exact: true }).click();
  const journalEntry = page.locator('.edu-journal__entry').filter({ hasText: title });
  await journalEntry.getByText(comment, { exact: true }).waitFor();
  await journalEntry.getByText('Освоено', { exact: true }).waitFor();
  const journal = (await api(`${groupPath}/journal`)).items;
  const journalResult = journal
    .find(({ id }) => id === lesson.id)?.results.find(({ studentId }) => studentId === student.id);
  assert.equal(journalResult?.status, 'mastered');
  assert.equal(journalResult.comment, comment);
  console.log('Teacher enrollment, attendance, assessment, student card and journal verified.');
}
