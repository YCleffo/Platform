import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ActivityAttempt } from '../activityApi';
import TypingArenaPlugin from './TypingArenaPlugin.vue';

vi.mock('../activityI18n', () => ({
  actT: (key: string) => key,
}));

describe('TypingArenaPlugin', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-11-27T10:00:00Z'));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('highlights errors per character, handles backspace, and auto-submits in words mode', async () => {
    const wrapper = mount(TypingArenaPlugin, {
      props: {
        exercise: {
          promptText: 'abc def',
          submode: 'words',
          mode: 'words',
          language: 'en',
          timeLimitSeconds: 0,
          wordCount: 2,
        },
      },
    });

    const input = wrapper.get('textarea');
    await input.setValue('ax');
    expect(wrapper.findAll('.uact-char--err')).toHaveLength(1);
    expect(wrapper.findAll('.uact-char--ok')).toHaveLength(1);

    // Backspace clears error
    await input.setValue('a');
    expect(wrapper.findAll('.uact-char--err')).toHaveLength(0);

    // Complete prompt auto-submits
    await input.setValue('abc def');
    expect(wrapper.emitted('submit')).toHaveLength(1);
    const [submission, telemetry] = wrapper.emitted('submit')![0] as [
      Record<string, unknown>,
      Record<string, unknown>,
    ];
    expect(submission.typedText).toBe('abc def');
    expect(telemetry.telemetryVerified).toBe(false);
    expect(telemetry.corrections).toBe(1);
  });

  it('ignores intermediate IME composition and commits final text once', async () => {
    const wrapper = mount(TypingArenaPlugin, {
      props: {
        exercise: {
          promptText: 'привет',
          submode: 'words',
          mode: 'words',
          language: 'ru',
          timeLimitSeconds: 0,
        },
      },
    });

    const input = wrapper.get('textarea');
    await input.trigger('compositionstart');
    input.element.value = 'прX';
    input.element.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true }));
    await flushPromises();
    expect(wrapper.findAll('.uact-char--err')).toHaveLength(0);

    input.element.value = 'привет';
    await input.trigger('compositionend');
    await flushPromises();
    expect(wrapper.emitted('submit')).toHaveLength(1);
  });

  it('finishes time-mode session automatically when countdown reaches 0', async () => {
    const wrapper = mount(TypingArenaPlugin, {
      props: {
        exercise: {
          promptText: 'one two three four five',
          submode: 'time',
          mode: 'words',
          language: 'en',
          timeLimitSeconds: 15,
        },
      },
    });

    const input = wrapper.get('textarea');
    await input.setValue('one');
    await vi.advanceTimersByTimeAsync(15100);
    await flushPromises();

    expect(wrapper.emitted('submit')).toHaveLength(1);
  });

  it('locks mode controls when assigned and renders results screen with SVG chart', async () => {
    const evaluatedAttempt: ActivityAttempt = {
      id: 'att-1',
      activityId: 'act-1',
      versionId: 'ver-1',
      assignmentId: 'asgn-1',
      participantPersonId: 'per-1',
      state: 'EVALUATED',
      revision: 2,
      seed: 1,
      pluginCode: 'platform-typing',
      activityTitle: 'Typing Arena',
      config: { language: 'en', submode: 'time' },
      exercise: { promptText: 'hello world', submode: 'time', language: 'en', timeLimitSeconds: 15 },
      answers: { typedText: 'hello world' },
      telemetry: {},
      evaluation: {
        rawScore: 55,
        maxScore: 55,
        percentage: 100,
        passed: true,
        telemetryVerified: false,
        metrics: {
          wpm: 55,
          rawWpm: 58,
          cpm: 275,
          accuracy: 100,
          consistency: 94,
          errorCount: 0,
          elapsedSeconds: 15,
          wpmSamples: [
            { second: 1, wpm: 50, rawWpm: 52, errors: 0 },
            { second: 2, wpm: 55, rawWpm: 58, errors: 0 },
          ],
        },
      },
      rawScore: 55,
      maxScore: 55,
      percentage: 100,
      passed: true,
      startedAt: null,
      expiresAt: null,
      submittedAt: null,
      evaluatedAt: null,
      reviewNotes: null,
    };

    const wrapper = mount(TypingArenaPlugin, {
      props: {
        exercise: evaluatedAttempt.exercise,
        attempt: evaluatedAttempt,
        readonly: true,
        assigned: true,
      },
    });

    expect(wrapper.find('.uact-arena__toolbar').exists()).toBe(false);
    expect(wrapper.find('.uact-arena__results').exists()).toBe(true);
    expect(wrapper.find('svg.uact-arena__chart').exists()).toBe(true);
    expect(wrapper.text()).toContain('55');
    expect(wrapper.text()).toContain('275');
  });
});
