export interface JournalLessonEntry {
  id: string;
  results: Array<{
    studentId: string;
    status: string;
    comment?: string | null;
    name: string;
  }>;
}

export interface StudentJournalStats {
  attended: number;
  total: number;
  percent: number;
  absences: number;
  avgGrade: number | null;
  avgFormatted: string;
  gradeClass: string;
}

export interface LessonJournalSummary {
  avg: string;
  attendancePercent: number;
}

export function resultStatusToScore(status: string): number | null {
  if (status === 'mastered') return 5;
  if (status === 'developing') return 4;
  if (status === 'needs_support') return 3;
  return null;
}

export function resultStatusKey(status: string): string {
  if (status === 'pending') return 'notAssessed';
  if (status === 'needs_support') return 'needsSupport';
  return status;
}

export function resultStatusTone(status: string): string {
  return status === 'needs_support' ? 'cancelled' : status;
}

export function computeStudentJournalStats(
  lessons: JournalLessonEntry[],
  studentId: string,
): StudentJournalStats {
  let attended = 0;
  let total = 0;
  const grades: number[] = [];
  for (const lesson of lessons) {
    const res = lesson.results.find((r) => r.studentId === studentId);
    if (res) {
      total++;
      if (res.status !== 'pending') {
        attended++;
      }
      const score = resultStatusToScore(res.status);
      if (score !== null) {
        grades.push(score);
      }
    }
  }
  const percent = total > 0 ? Math.round((attended / total) * 100) : 0;
  const absences = total - attended;
  const avg = grades.length > 0 ? grades.reduce((a, b) => a + b, 0) / grades.length : null;
  const avgFormatted = avg !== null ? avg.toFixed(1) : '—';
  const gradeClass =
    avg !== null ? (avg >= 4.5 ? 'completed' : avg >= 3.5 ? 'now' : 'cancelled') : '';
  return { attended, total, percent, absences, avgGrade: avg, avgFormatted, gradeClass };
}

export function computeLessonSummaryMap(
  lessons: JournalLessonEntry[],
): Record<string, LessonJournalSummary> {
  const map: Record<string, LessonJournalSummary> = {};
  for (const lesson of lessons) {
    let attended = 0;
    const total = lesson.results.length;
    const scores: number[] = [];
    for (const res of lesson.results) {
      if (res.status !== 'pending') {
        attended++;
      }
      const s = resultStatusToScore(res.status);
      if (s !== null) scores.push(s);
    }
    const pct = total > 0 ? Math.round((attended / total) * 100) : 0;
    const avg =
      scores.length > 0 ? (scores.reduce((a, b) => a + b, 0) / scores.length).toFixed(1) : '—';
    map[lesson.id] = { avg, attendancePercent: pct };
  }
  return map;
}

export function computeOverallGroupStats(
  lessons: JournalLessonEntry[],
  studentIds: string[],
): { avgGrade: string; attendancePercent: number; totalAbsences: number } {
  let totalAttended = 0;
  let totalLessons = 0;
  let totalAbsences = 0;
  const allScores: number[] = [];
  for (const sId of studentIds) {
    const stats = computeStudentJournalStats(lessons, sId);
    totalAttended += stats.attended;
    totalLessons += stats.total;
    totalAbsences += stats.absences;
    if (stats.avgGrade !== null) {
      allScores.push(stats.avgGrade);
    }
  }
  const pct = totalLessons > 0 ? Math.round((totalAttended / totalLessons) * 100) : 0;
  const avg =
    allScores.length > 0
      ? (allScores.reduce((a, b) => a + b, 0) / allScores.length).toFixed(1)
      : '—';
  return { avgGrade: avg, attendancePercent: pct, totalAbsences };
}
