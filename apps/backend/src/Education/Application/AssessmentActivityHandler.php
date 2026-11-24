<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Education\Activity\ActivityPluginInterface;

final class AssessmentActivityHandler implements ActivityPluginInterface
{
    public function code(): string
    {
        return 'platform-assessment';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function title(): string
    {
        return 'Assessment Studio';
    }

    public function description(): string
    {
        return 'Comprehensive interactive knowledge assessment and quiz runner with multiple question formats.';
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assessmentId' => ['type' => 'string'],
                'versionId' => ['type' => 'string'],
                'timeLimitSeconds' => ['type' => 'integer', 'minimum' => 30, 'maximum' => 14400],
                'passingScorePercent' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'shuffleQuestions' => ['type' => 'boolean'],
                'shuffleOptions' => ['type' => 'boolean'],
                'maxAttempts' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                'revealAnswersPolicy' => ['type' => 'string', 'enum' => ['never', 'immediate', 'after_grading', 'deadline']],
                'gradingPolicy' => ['type' => 'string', 'enum' => ['highest', 'latest', 'average']],
            ],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'passingScorePercent' => 60,
            'timeLimitSeconds' => 1800,
            'shuffleQuestions' => false,
            'shuffleOptions' => false,
            'maxAttempts' => 1,
            'revealAnswersPolicy' => 'after_grading',
            'gradingPolicy' => 'highest',
        ];
    }

    public function validateConfig(array $config): array
    {
        $allowed = [
            'assessmentId', 'versionId', 'timeLimitSeconds', 'passingScorePercent',
            'shuffleQuestions', 'shuffleOptions', 'maxAttempts', 'revealAnswersPolicy',
            'gradingPolicy', 'title', 'instructions', 'questions',
        ];
        ActivityHandlerRegistry::only($config, $allowed);

        $out = array_replace($this->defaultConfig(), $config);

        if (isset($out['timeLimitSeconds']) && $out['timeLimitSeconds'] !== null) {
            ActivityHandlerRegistry::integer($out['timeLimitSeconds'], 30, 14400);
        }
        ActivityHandlerRegistry::integer($out['passingScorePercent'], 0, 100);
        ActivityHandlerRegistry::integer($out['maxAttempts'], 1, 20);

        if (!in_array($out['revealAnswersPolicy'], ['never', 'immediate', 'after_grading', 'deadline'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        if (!in_array($out['gradingPolicy'], ['highest', 'latest', 'average'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        if (!is_bool($out['shuffleQuestions']) || !is_bool($out['shuffleOptions'])) {
            throw new EducationError(422, 'invalid_input');
        }

        if (isset($out['assessmentId']) && $out['assessmentId'] !== null) {
            Input::uuid($out['assessmentId']);
        }
        if (isset($out['versionId']) && $out['versionId'] !== null) {
            Input::uuid($out['versionId']);
        }

        return $out;
    }

    public function createExercise(array $config, ?array $set = null): array
    {
        $questions = $set['questions'] ?? ($config['questions'] ?? null);
        if ($questions === null || !is_array($questions) || $questions === []) {
            // Default built-in standard questions for general test runs
            $questions = $this->defaultSampleQuestions();
        }

        if ($config['shuffleQuestions'] ?? false) {
            shuffle($questions);
        }

        if ($config['shuffleOptions'] ?? false) {
            foreach ($questions as &$q) {
                if (is_array($q) && isset($q['options']) && is_array($q['options']) && count($q['options']) > 1) {
                    $optionsCopy = $q['options'];
                    shuffle($optionsCopy);
                    $q['options'] = $optionsCopy;
                }
            }
            unset($q);
        }

        return [
            'assessmentId' => $config['assessmentId'] ?? null,
            'versionId' => $config['versionId'] ?? null,
            'title' => $config['title'] ?? 'Assessment Test',
            'timeLimitSeconds' => $config['timeLimitSeconds'] ?? null,
            'passingScorePercent' => $config['passingScorePercent'] ?? 60,
            'revealAnswersPolicy' => $config['revealAnswersPolicy'] ?? 'after_grading',
            'questions' => $questions,
            'version' => 1,
            'code' => $this->code(),
        ];
    }

    public function publicExercise(array $attempt): array
    {
        $exercise = ActivityHandlerRegistry::json($attempt['exercisePayload'] ?? []);
        $questionsRaw = ActivityHandlerRegistry::json($exercise['questions'] ?? []);
        /** @var list<array<string, mixed>> $questions */
        $questions = array_values(array_filter($questionsRaw, 'is_array'));
        $publicQuestions = [];

        foreach ($questions as $q) {
            // Strip server-only private answer keys and explanations
            $publicQ = [
                'id' => (string) ($q['id'] ?? ''),
                'type' => (string) ($q['type'] ?? 'single_choice'),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'points' => (int) ($q['points'] ?? 1),
            ];

            if (isset($q['options']) && is_array($q['options'])) {
                $publicQ['options'] = array_map(static fn(array $opt): array => [
                    'id' => (string) ($opt['id'] ?? ''),
                    'label' => (string) ($opt['label'] ?? ''),
                ], $q['options']);
            }

            if (isset($q['items']) && is_array($q['items'])) {
                $publicQ['items'] = $q['items'];
            }

            $publicQuestions[] = $publicQ;
        }

        return [
            'assessmentId' => $exercise['assessmentId'] ?? null,
            'versionId' => $exercise['versionId'] ?? null,
            'title' => $exercise['title'] ?? 'Assessment',
            'timeLimitSeconds' => $exercise['timeLimitSeconds'] ?? null,
            'passingScorePercent' => $exercise['passingScorePercent'] ?? 60,
            'questions' => $publicQuestions,
            'version' => $exercise['version'] ?? 1,
            'code' => $this->code(),
        ];
    }

    public function validateState(array $attempt, array $state): array
    {
        ActivityHandlerRegistry::only($state, ['answers', 'currentQuestionIndex', 'flaggedQuestions', 'elapsedSeconds']);
        if (isset($state['answers']) && !is_array($state['answers'])) {
            throw new EducationError(422, 'invalid_input');
        }
        if (isset($state['currentQuestionIndex'])) {
            ActivityHandlerRegistry::integer($state['currentQuestionIndex'], 0, 1000);
        }
        if (isset($state['elapsedSeconds'])) {
            ActivityHandlerRegistry::integer($state['elapsedSeconds'], 0, 86400);
        }
        return $state;
    }

    public function evaluate(array $attempt, array $input): array
    {
        ActivityHandlerRegistry::only($input, ['answers', 'elapsedSeconds']);
        $answers = $input['answers'] ?? [];
        if (!is_array($answers)) {
            throw new EducationError(422, 'invalid_input');
        }

        $exercise = ActivityHandlerRegistry::json($attempt['exercisePayload'] ?? []);
        $questionsRaw = ActivityHandlerRegistry::json($exercise['questions'] ?? []);
        /** @var list<array<string, mixed>> $questions */
        $questions = array_values(array_filter($questionsRaw, 'is_array'));
        $passingScorePercent = (int) ($exercise['passingScorePercent'] ?? 60);

        $earnedPoints = 0;
        $totalPossiblePoints = 0;
        $evaluations = [];

        foreach ($questions as $q) {
            $qId = (string) ($q['id'] ?? '');
            $qType = (string) ($q['type'] ?? 'single_choice');
            $points = (int) ($q['points'] ?? 1);
            $totalPossiblePoints += $points;

            $clientAnswer = $answers[$qId] ?? null;
            $answerKey = $q['answerKey'] ?? null;
            $isCorrect = false;

            switch ($qType) {
                case 'single_choice':
                    $isCorrect = is_string($clientAnswer) && is_string($answerKey) && $clientAnswer === $answerKey;
                    break;

                case 'multi_choice':
                    if (is_array($clientAnswer) && is_array($answerKey)) {
                        $cSorted = array_values(array_unique(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $clientAnswer)));
                        $aSorted = array_values(array_unique(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $answerKey)));
                        sort($cSorted);
                        sort($aSorted);
                        $isCorrect = $cSorted === $aSorted;
                    }
                    break;

                case 'true_false':
                    $isCorrect = is_bool($clientAnswer) && $clientAnswer === (bool) $answerKey;
                    break;

                case 'short_text':
                    if (is_string($clientAnswer)) {
                        $normClient = mb_strtolower(trim($clientAnswer), 'UTF-8');
                        if (is_array($answerKey)) {
                            foreach ($answerKey as $accepted) {
                                if ($normClient === mb_strtolower(trim((string) $accepted), 'UTF-8')) {
                                    $isCorrect = true;
                                    break;
                                }
                            }
                        } elseif (is_string($answerKey)) {
                            $isCorrect = $normClient === mb_strtolower(trim($answerKey), 'UTF-8');
                        }
                    }
                    break;

                case 'numeric':
                    if (is_numeric($clientAnswer) && is_numeric($answerKey)) {
                        $tolerance = isset($q['tolerance']) && is_numeric($q['tolerance']) ? (float) $q['tolerance'] : 0.001;
                        $isCorrect = abs((float) $clientAnswer - (float) $answerKey) <= $tolerance;
                    }
                    break;

                default:
                    $isCorrect = false;
                    break;
            }

            $questionEarned = $isCorrect ? $points : 0;
            $earnedPoints += $questionEarned;

            $evaluations[] = [
                'questionId' => $qId,
                'type' => $qType,
                'correct' => $isCorrect,
                'earnedPoints' => $questionEarned,
                'possiblePoints' => $points,
                'explanation' => (string) ($q['explanation'] ?? ''),
                'correctAnswer' => $answerKey,
                'clientAnswer' => $clientAnswer,
            ];
        }

        $percentage = $totalPossiblePoints > 0 ? round(($earnedPoints / $totalPossiblePoints) * 100, 2) : 0.0;
        $passed = $percentage >= $passingScorePercent;

        $metrics = [
            'score' => $earnedPoints,
            'maxScore' => $totalPossiblePoints,
            'percentage' => $percentage,
            'passed' => $passed,
            'passingScorePercent' => $passingScorePercent,
            'totalQuestions' => count($questions),
            'correctQuestions' => count(array_filter($evaluations, static fn(array $e): bool => $e['correct'])),
            'elapsedSeconds' => (int) ($input['elapsedSeconds'] ?? 0),
        ];

        $canonical = [
            'answers' => $answers,
            'elapsedSeconds' => (int) ($input['elapsedSeconds'] ?? 0),
            'version' => $exercise['version'] ?? 1,
        ];

        return [
            'type' => 'assessment',
            'version' => 1,
            'metrics' => $metrics,
            'questions' => $evaluations,
            'requestHash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'evidence' => [
                'score' => $earnedPoints,
                'maxScore' => $totalPossiblePoints,
                'percentage' => $percentage,
                'passed' => $passed,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function defaultSampleQuestions(): array
    {
        return [
            [
                'id' => 'q1',
                'type' => 'single_choice',
                'prompt' => 'Какая структура данных работает по принципу LIFO (Last In, First Out)?',
                'points' => 1,
                'options' => [
                    ['id' => 'opt_stack', 'label' => 'Стек (Stack)'],
                    ['id' => 'opt_queue', 'label' => 'Очередь (Queue)'],
                    ['id' => 'opt_list', 'label' => 'Связный список (Linked List)'],
                    ['id' => 'opt_tree', 'label' => 'Бинарное дерево (Tree)'],
                ],
                'answerKey' => 'opt_stack',
                'explanation' => 'Стек функционирует по принципу LIFO: последний добавленный элемент извлекается первым.',
            ],
            [
                'id' => 'q2',
                'type' => 'multi_choice',
                'prompt' => 'Какие из следующих протоколов относятся к прикладному уровню модели OSI? (Выберите все подходящие)',
                'points' => 2,
                'options' => [
                    ['id' => 'opt_http', 'label' => 'HTTP / HTTPS'],
                    ['id' => 'opt_dns', 'label' => 'DNS'],
                    ['id' => 'opt_tcp', 'label' => 'TCP'],
                    ['id' => 'opt_ip', 'label' => 'IP'],
                ],
                'answerKey' => ['opt_http', 'opt_dns'],
                'explanation' => 'HTTP и DNS — прикладные протоколы (L7), TCP — транспортный (L4), IP — сетевой (L3).',
            ],
            [
                'id' => 'q3',
                'type' => 'true_false',
                'prompt' => 'В языке JavaScript результат выражения typeof null равен "object".',
                'points' => 1,
                'answerKey' => true,
                'explanation' => 'Да, это историческая особенность реализации JavaScript с первой версии языка.',
            ],
            [
                'id' => 'q4',
                'type' => 'numeric',
                'prompt' => 'Чему равно значение 2 в 10-й степени?',
                'points' => 1,
                'answerKey' => 1024,
                'tolerance' => 0,
                'explanation' => '2^10 = 1024 (один килобайт в двоичной системе).',
            ],
            [
                'id' => 'q5',
                'type' => 'short_text',
                'prompt' => 'Назовите команду Git для создания новой ветки и мгновенного переключения на неё.',
                'points' => 2,
                'answerKey' => ['git switch -c', 'git checkout -b', 'switch -c', 'checkout -b'],
                'explanation' => 'Команды git switch -c <name> или git checkout -b <name> создают и переключают ветку.',
            ],
        ];
    }
}
