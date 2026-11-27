import { describe, expect, it } from 'vitest';
import type { ActivityAttempt } from '../activityApi';
import {
  buildPromptWords,
  calculateTypingStats,
  diffInputUpdate,
  normalizeTypingAttemptResult,
  splitGraphemes,
} from './typingEngine';

describe('typingEngine', () => {
  it('segments Unicode and Cyrillic grapheme clusters accurately', () => {
    expect(splitGraphemes('привет мир', 'ru')).toEqual([
      'п',
      'р',
      'и',
      'в',
      'е',
      'т',
      ' ',
      'м',
      'и',
      'р',
    ]);
    // Combining acute accent stays one grapheme
    expect(splitGraphemes('e\u0301clair', 'en')).toHaveLength(6);
  });

  it('builds word tokens preserving global grapheme indices', () => {
    const words = buildPromptWords(splitGraphemes('abc def', 'en'));
    expect(words).toHaveLength(2);
    expect(words[0]?.startIndex).toBe(0);
    expect(words[0]?.chars.map((c) => c.char).join('')).toBe('abc ');
    expect(words[1]?.startIndex).toBe(4);
    expect(words[1]?.chars.map((c) => c.index)).toEqual([4, 5, 6]);
  });

  it('tracks corrections and inputErrors across backspace, paste, and replacement', () => {
    const target = splitGraphemes('hello', 'en');
    const step1 = diffInputUpdate([], splitGraphemes('hx', 'en'), target, false);
    expect(step1).toEqual({ correctionsDelta: 0, inputErrorsDelta: 1 });

    // Backspace removes 'x'
    const step2 = diffInputUpdate(splitGraphemes('hx', 'en'), splitGraphemes('h', 'en'), target, false);
    expect(step2).toEqual({ correctionsDelta: 1, inputErrorsDelta: 0 });

    // Replace/paste rest of word correctly
    const step3 = diffInputUpdate(
      splitGraphemes('h', 'en'),
      splitGraphemes('hello', 'en'),
      target,
      false,
    );
    expect(step3).toEqual({ correctionsDelta: 0, inputErrorsDelta: 0 });
  });

  it('computes WPM, Raw WPM, CPM, Accuracy, and Consistency canonically', () => {
    const expected = splitGraphemes('hello world', 'en');
    const actual = splitGraphemes('hello worlx', 'en'); // 10 correct, 1 error, 11 total
    const stats = calculateTypingStats({
      actualGraphemes: actual,
      expectedGraphemes: expected,
      elapsedMs: 60000, // 1 minute
      wpmSamples: [
        { second: 1, wpm: 2, rawWpm: 2.2, errors: 0 },
        { second: 2, wpm: 2, rawWpm: 2.2, errors: 1 },
      ],
      isZen: false,
    });

    expect(stats.correctChars).toBe(10);
    expect(stats.errors).toBe(1);
    expect(stats.wpm).toBe(2);
    expect(stats.rawWpm).toBe(2.2);
    expect(stats.cpm).toBe(10);
    expect(stats.accuracy).toBe(90.9);
    expect(stats.consistency).toBe(100);
  });

  it('normalizes evaluated ActivityAttempt into TypingCanonicalResult', () => {
    const attempt: ActivityAttempt = {
      id: 'att-1',
      activityId: 'act-1',
      versionId: 'ver-1',
      assignmentId: null,
      participantPersonId: 'per-1',
      state: 'EVALUATED',
      revision: 2,
      seed: 42,
      pluginCode: 'platform-typing',
      activityTitle: 'Typing Arena',
      config: { language: 'ru', submode: 'time' },
      exercise: { promptText: 'привет' },
      answers: { typedText: 'привет' },
      telemetry: { backspaceCount: 1 },
      evaluation: {
        rawScore: 60,
        maxScore: 60,
        percentage: 100,
        passed: true,
        telemetryVerified: false,
        metrics: {
          wpm: 60,
          rawWpm: 62,
          cpm: 300,
          accuracy: 100,
          consistency: 92,
          correctChars: 6,
          totalTypedChars: 6,
          errorCount: 0,
          corrections: 1,
          elapsedSeconds: 10,
          language: 'ru',
          mode: 'words',
          submode: 'time',
          wpmSamples: [{ second: 1, wpm: 60, rawWpm: 62, errors: 0 }],
        },
      },
      rawScore: 60,
      maxScore: 60,
      percentage: 100,
      passed: true,
      startedAt: '2026-11-27T00:00:00Z',
      expiresAt: null,
      submittedAt: '2026-11-27T00:00:10Z',
      evaluatedAt: '2026-11-27T00:00:10Z',
      reviewNotes: null,
    };

    const res = normalizeTypingAttemptResult(attempt);
    expect(res.wpm).toBe(60);
    expect(res.cpm).toBe(300);
    expect(res.telemetryVerified).toBe(false);
    expect(res.language).toBe('ru');
    expect(res.wpmSamples).toHaveLength(1);
  });
});
