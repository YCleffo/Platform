import type { Lesson } from './api';

export type LessonDisplayStatus = 'scheduled' | 'completed' | 'cancelled' | 'now';

export function lessonDisplayStatus(lesson: Lesson, now: string): LessonDisplayStatus {
  if (lesson.status !== 'scheduled') return lesson.status;
  const moment = Date.parse(now);
  return Date.parse(lesson.startsAt) <= moment && Date.parse(lesson.endsAt) > moment
    ? 'now'
    : 'scheduled';
}

export function lessonStarted(lesson: Lesson, now: string): boolean {
  return Date.parse(lesson.startsAt) <= Date.parse(now);
}
