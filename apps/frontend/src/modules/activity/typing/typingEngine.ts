import type { ActivityAttempt } from '../activityApi';

export type TypingSubmode = 'time' | 'words' | 'quote' | 'zen' | 'custom';
export type TypingMode =
  | 'words'
  | 'sentences'
  | 'numbers'
  | 'code'
  | 'quote'
  | 'zen'
  | 'accuracy'
  | 'custom';
export type TypingLanguage = 'en' | 'ru';

export interface TypingArenaConfig {
  language: TypingLanguage;
  submode: TypingSubmode;
  mode: TypingMode;
  timeLimitSeconds: number;
  wordCount: number;
  difficulty: 1 | 2 | 3;
  punctuation: boolean;
  numbers: boolean;
  customText: string;
}

export interface WpmSample {
  second: number;
  wpm: number;
  rawWpm: number;
  errors: number;
}

export interface TypingLiveStats {
  wpm: number;
  rawWpm: number;
  cpm: number;
  accuracy: number;
  consistency: number;
  correctChars: number;
  totalTypedChars: number;
  errors: number;
}

export interface TypingPromptWord {
  startIndex: number;
  chars: Array<{ index: number; char: string }>;
}

export interface TypingCanonicalResult {
  attemptId: string;
  wpm: number;
  rawWpm: number;
  cpm: number;
  accuracy: number;
  consistency: number | null;
  correctChars: number;
  totalTypedChars: number;
  errorCount: number;
  corrections: number;
  elapsedSeconds: number;
  passed: boolean | null;
  telemetryVerified: boolean;
  wpmSamples: WpmSample[];
  language: string;
  mode: string;
  submode: string;
  completedAt: string | null;
}

const segmenterCache = new Map<string, Intl.Segmenter>();

function getGraphemeSegmenter(locale = 'en'): Intl.Segmenter | null {
  if (typeof Intl === 'undefined' || !('Segmenter' in Intl)) return null;
  const loc = locale === 'ru' ? 'ru-RU' : 'en-US';
  let seg = segmenterCache.get(loc);
  if (!seg) {
    seg = new Intl.Segmenter(loc, { granularity: 'grapheme' });
    segmenterCache.set(loc, seg);
  }
  return seg;
}

/**
 * Splits a string into Unicode grapheme clusters (handles Cyrillic, combining marks, emoji).
 */
export function splitGraphemes(input: string, locale = 'en'): string[] {
  if (!input) return [];
  const seg = getGraphemeSegmenter(locale);
  if (seg) {
    const out: string[] = [];
    for (const part of seg.segment(input)) {
      out.push(part.segment);
    }
    return out;
  }
  return Array.from(input);
}

/**
 * Groups graphemes into word tokens so CSS flex/inline-block wrapping never splits a word mid-line,
 * while preserving each character's exact global grapheme index.
 */
export function buildPromptWords(graphemes: string[]): TypingPromptWord[] {
  const words: TypingPromptWord[] = [];
  let current: TypingPromptWord | null = null;

  for (let i = 0; i < graphemes.length; i++) {
    const ch = graphemes[i] ?? '';
    if (!current) {
      current = { startIndex: i, chars: [] };
    }
    current.chars.push({ index: i, char: ch });
    if (ch === ' ' || ch === '\n') {
      words.push(current);
      current = null;
    }
  }
  if (current) {
    words.push(current);
  }
  return words;
}

/**
 * Computes incremental corrections & inputErrors between two grapheme arrays.
 */
export function diffInputUpdate(
  prevGraphemes: string[],
  nextGraphemes: string[],
  targetGraphemes: string[],
  isZen: boolean,
): { correctionsDelta: number; inputErrorsDelta: number } {
  let prefix = 0;
  let suffix = 0;
  while (
    prefix < prevGraphemes.length &&
    prefix < nextGraphemes.length &&
    prevGraphemes[prefix] === nextGraphemes[prefix]
  ) {
    prefix++;
  }
  while (
    suffix < prevGraphemes.length - prefix &&
    suffix < nextGraphemes.length - prefix &&
    prevGraphemes[prevGraphemes.length - 1 - suffix] ===
      nextGraphemes[nextGraphemes.length - 1 - suffix]
  ) {
    suffix++;
  }

  const correctionsDelta = Math.max(0, prevGraphemes.length - prefix - suffix);
  let inputErrorsDelta = 0;
  if (!isZen) {
    const end = nextGraphemes.length - suffix;
    for (let i = prefix; i < end; i++) {
      if (nextGraphemes[i] !== targetGraphemes[i]) {
        inputErrorsDelta++;
      }
    }
  }
  return { correctionsDelta, inputErrorsDelta };
}

/**
 * Single canonical client-side typing statistics calculator (matches server TypingActivityPlugin).
 */
export function calculateTypingStats(params: {
  actualGraphemes: string[];
  expectedGraphemes: string[];
  elapsedMs: number;
  wpmSamples: WpmSample[];
  isZen: boolean;
}): TypingLiveStats {
  const { actualGraphemes, expectedGraphemes, elapsedMs, wpmSamples, isZen } = params;
  const total = actualGraphemes.length;
  let correct = 0;
  let errors = 0;

  if (isZen) {
    correct = total;
  } else {
    const limit = Math.min(total, expectedGraphemes.length);
    for (let i = 0; i < limit; i++) {
      if (actualGraphemes[i] === expectedGraphemes[i]) {
        correct++;
      } else {
        errors++;
      }
    }
    if (total > expectedGraphemes.length) {
      errors += total - expectedGraphemes.length;
    }
  }

  const minutes = Math.max(elapsedMs / 1000, 1) / 60;
  const wpm = total > 0 ? Math.round((correct / 5 / minutes) * 10) / 10 : 0;
  const rawWpm = total > 0 ? Math.round((total / 5 / minutes) * 10) / 10 : 0;
  const cpm = total > 0 ? Math.round(correct / minutes) : 0;
  const accuracy = total > 0 ? Math.round((correct / total) * 1000) / 10 : 100;

  let consistency = 100;
  if (wpmSamples.length >= 2) {
    const vals = wpmSamples.map((s) => s.wpm);
    const mean = vals.reduce((a, b) => a + b, 0) / vals.length;
    if (mean > 0) {
      const variance = vals.reduce((a, b) => a + (b - mean) ** 2, 0) / vals.length;
      consistency = Math.max(0, Math.min(100, Math.round((1 - Math.sqrt(variance) / mean) * 100)));
    }
  }

  return {
    wpm,
    rawWpm,
    cpm,
    accuracy,
    consistency,
    correctChars: correct,
    totalTypedChars: total,
    errors,
  };
}

export function normalizeTypingAttemptResult(attempt: ActivityAttempt): TypingCanonicalResult {
  const m = (attempt.evaluation?.metrics ?? {}) as Record<string, unknown>;
  const tel = (attempt.telemetry ?? {}) as Record<string, unknown>;
  const rawSamples = Array.isArray(m.wpmSamples)
    ? m.wpmSamples
    : Array.isArray(tel.wpmSamples)
      ? tel.wpmSamples
      : [];

  const wpmSamples: WpmSample[] = rawSamples
    .filter((s): s is Record<string, unknown> => typeof s === 'object' && s !== null)
    .map((s, idx) => ({
      second: Number(s.second ?? idx + 1),
      wpm: Number(s.wpm ?? 0),
      rawWpm: Number(s.rawWpm ?? s.wpm ?? 0),
      errors: Number(s.errors ?? 0),
    }));

  return {
    attemptId: attempt.id,
    wpm: Number(m.wpm ?? attempt.rawScore ?? 0),
    rawWpm: Number(m.rawWpm ?? m.wpm ?? 0),
    cpm: Number(m.cpm ?? Math.round(Number(m.wpm ?? 0) * 5)),
    accuracy: Number(m.accuracy ?? attempt.percentage ?? 0),
    consistency: m.consistency !== null && m.consistency !== undefined ? Number(m.consistency) : null,
    correctChars: Number(m.correctChars ?? 0),
    totalTypedChars: Number(m.totalTypedChars ?? 0),
    errorCount: Number(m.errorCount ?? 0),
    corrections: Number(m.corrections ?? tel.backspaceCount ?? 0),
    elapsedSeconds: Number(m.elapsedSeconds ?? tel.elapsedSeconds ?? 0),
    passed: attempt.passed,
    telemetryVerified: Boolean(attempt.evaluation?.telemetryVerified ?? false),
    wpmSamples,
    language: String(m.language ?? attempt.config?.language ?? 'en'),
    mode: String(m.mode ?? attempt.config?.mode ?? 'words'),
    submode: String(m.submode ?? attempt.config?.submode ?? 'time'),
    completedAt: attempt.evaluatedAt ?? attempt.submittedAt ?? null,
  };
}

export function defaultTypingArenaConfig(partial?: Record<string, unknown>): TypingArenaConfig {
  const rawSub = String(partial?.submode ?? 'time');
  const submode: TypingSubmode = ['time', 'words', 'quote', 'zen', 'custom'].includes(rawSub)
    ? (rawSub as TypingSubmode)
    : 'time';
  const rawMode = String(partial?.mode ?? 'words');
  const mode: TypingMode = [
    'words',
    'sentences',
    'numbers',
    'code',
    'quote',
    'zen',
    'accuracy',
    'custom',
  ].includes(rawMode)
    ? (rawMode as TypingMode)
    : 'words';
  const diff = Number(partial?.difficulty ?? 1);
  return {
    language: partial?.language === 'ru' ? 'ru' : 'en',
    submode: mode === 'zen' ? 'zen' : mode === 'quote' ? 'quote' : submode,
    mode,
    timeLimitSeconds: Number(partial?.timeLimitSeconds ?? partial?.durationSeconds ?? 60),
    wordCount: Number(partial?.wordCount ?? 30),
    difficulty: diff === 2 || diff === 3 ? diff : 1,
    punctuation: Boolean(partial?.punctuation),
    numbers: Boolean(partial?.numbers),
    customText: typeof partial?.customText === 'string' ? partial.customText : '',
  };
}
