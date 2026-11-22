import type { GroupProgram } from './api';

export function curriculumLessonOptions(programs: readonly GroupProgram[]) {
  const options = new Map<string, { value: string; label: string }>();
  for (const program of programs) {
    if (program.endedAt) continue;
    for (const module of program.modules) {
      for (const lesson of module.lessons) {
        if (!options.has(lesson.id)) {
          options.set(lesson.id, {
            value: lesson.id,
            label: `${program.name} / ${module.name} / ${lesson.title}`,
          });
        }
      }
    }
  }
  return [...options.values()];
}

export function curriculumSnapshotLabel(snapshot?: Record<string, unknown> | null): string {
  return [snapshot?.programName, snapshot?.moduleName, snapshot?.title]
    .filter((label): label is string => typeof label === 'string' && label.length > 0)
    .join(' / ');
}
