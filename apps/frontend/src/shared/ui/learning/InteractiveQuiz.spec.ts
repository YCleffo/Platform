import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import InteractiveQuiz from './InteractiveQuiz.vue';
import type { QuizActivityData } from './types';

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      if (params?.score !== undefined) {
        return `${key}:${params.score}/${params.total}`;
      }
      return key;
    },
  }),
}));

const mockQuiz: QuizActivityData = {
  id: 'test-quiz',
  title: 'Тест на знание алгоритмов',
  description: 'Проверка базовых знаний',
  passingScorePercent: 60,
  questions: [
    {
      id: 'q1',
      type: 'single_choice',
      prompt: 'Что выведет print(2 ** 3)?',
      options: [
        { id: 'opt1', text: '6' },
        { id: 'opt2', text: '8' },
        { id: 'opt3', text: '9' },
      ],
      correctAnswer: 'opt2',
      explanation: 'Оператор ** возводит число в степень.',
    },
    {
      id: 'q2',
      type: 'multi_choice',
      prompt: 'Какие типы данных являются неизменяемыми?',
      options: [
        { id: 'm1', text: 'tuple' },
        { id: 'm2', text: 'int' },
        { id: 'm3', text: 'list' },
      ],
      correctAnswer: ['m1', 'm2'],
      explanation: 'Кортежи и целые числа неизменяемы, а списки изменяемы.',
    },
    {
      id: 'q3',
      type: 'ordering',
      prompt: 'Расположите этапы в правильном порядке:',
      options: [
        { id: 'step-a', text: 'Шаг 1: Старт' },
        { id: 'step-b', text: 'Шаг 2: Обработка' },
        { id: 'step-c', text: 'Шаг 3: Финиш' },
      ],
      correctAnswer: ['step-a', 'step-b', 'step-c'],
      explanation: 'Последовательность от старта к финишу.',
    },
  ],
};

describe('InteractiveQuiz', () => {
  it('renders questions, prompts, and option cards', () => {
    const wrapper = mount(InteractiveQuiz, {
      props: { quiz: mockQuiz },
    });

    expect(wrapper.text()).toContain('Тест на знание алгоритмов');
    expect(wrapper.text()).toContain('Что выведет print(2 ** 3)?');
    expect(wrapper.findAll('.app-quiz__card').length).toBe(3);
  });

  it('selects single choice answer and evaluates correct answer', async () => {
    const wrapper = mount(InteractiveQuiz, {
      props: { quiz: mockQuiz },
    });

    // Select opt2 on question 1
    const opt2 = wrapper.findAll('.app-quiz__option')[1]!;
    await opt2.trigger('click');
    expect(opt2.classes()).toContain('app-quiz__option--selected');

    // Select multi choice m1 and m2 on question 2
    const multiOpts = wrapper.findAll('[role="checkbox"]');
    await multiOpts[0]!.trigger('click'); // m1
    await multiOpts[1]!.trigger('click'); // m2

    // Submit
    const submitBtn = wrapper.find('.app-quiz__btn--submit');
    await submitBtn.trigger('click');

    expect(wrapper.emitted('complete')).toBeTruthy();
    const result = wrapper.emitted('complete')![0]![0] as {
      score: number;
      passed: boolean;
    };
    expect(result.score).toBe(3); // q1 (correct), q2 (correct), q3 (initial order matches correct)
    expect(result.passed).toBe(true);
    expect(wrapper.find('.app-quiz__result-banner--pass').exists()).toBe(true);
  });

  it('allows reordering items via up/down buttons', async () => {
    const wrapper = mount(InteractiveQuiz, {
      props: { quiz: mockQuiz },
    });

    // In question 3 (ordering), click down on the first item
    const orderItems = wrapper.findAll('.app-quiz__order-item');
    expect(orderItems.length).toBe(3);

    const downBtn = orderItems[0]!.find('.app-quiz__arrow-btn:last-child');
    await downBtn.trigger('click');

    // The order should now be step-b, step-a, step-c
    const updatedItems = wrapper.findAll('.app-quiz__order-item');
    expect(updatedItems[0]!.text()).toContain('Шаг 2: Обработка');
    expect(updatedItems[1]!.text()).toContain('Шаг 1: Старт');
  });

  it('shows explanations and wrong highlight when incorrect answer is given', async () => {
    const wrapper = mount(InteractiveQuiz, {
      props: { quiz: mockQuiz },
    });

    // Select wrong opt1 on question 1
    const opt1 = wrapper.findAll('.app-quiz__option')[0]!;
    await opt1.trigger('click');

    // Select only m1 (incomplete) on question 2
    const multiOpts = wrapper.findAll('[role="checkbox"]');
    await multiOpts[0]!.trigger('click');

    // Submit
    const submitBtn = wrapper.find('.app-quiz__btn--submit');
    await submitBtn.trigger('click');

    const result = wrapper.emitted('complete')![0]![0] as {
      score: number;
      passed: boolean;
    };
    expect(result.score).toBe(1); // only q3 ordering matched
    expect(result.passed).toBe(false);
    expect(wrapper.text()).toContain('Оператор ** возводит число в степень.');
  });
});
