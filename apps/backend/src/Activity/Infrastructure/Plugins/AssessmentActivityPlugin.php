<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Plugins;

use App\Activity\Application\ActivityPayload;
use App\Activity\Application\ActivityPluginInterface;
use App\Shared\Application\HttpError;

final class AssessmentActivityPlugin implements ActivityPluginInterface
{
    public function code(): string
    {
        return 'platform-assessment';
    }

    public function version(): int
    {
        return 2;
    }

    public function title(): string
    {
        return 'Assessment Studio 2.0';
    }

    public function category(): string
    {
        return 'assessment';
    }

    public function capabilities(): array
    {
        return ['supportsDrafts', 'supportsRealtime', 'supportsManualReview', 'supportsTimer', 'supportsAdaptive'];
    }

    public function configSchema(): array
    {
        return [
            'timeLimitSeconds' => ['type' => 'integer', 'nullable' => true, 'min' => 30, 'max' => 14400],
            'passingScorePercent' => ['type' => 'integer', 'min' => 0, 'max' => 100, 'default' => 60],
            'shuffleQuestions' => ['type' => 'boolean', 'default' => false],
            'shuffleOptions' => ['type' => 'boolean', 'default' => false],
            'partialCredit' => ['type' => 'boolean', 'default' => true],
            'revealAnswersPolicy' => ['type' => 'enum', 'values' => ['never', 'immediate', 'after_grading', 'deadline'], 'default' => 'after_grading'],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'timeLimitSeconds' => null,
            'passingScorePercent' => 60,
            'shuffleQuestions' => false,
            'shuffleOptions' => false,
            'partialCredit' => true,
            'revealAnswersPolicy' => 'after_grading',
        ];
    }

    public function safeParticipantConfigKeys(): array
    {
        return [];
    }

    public function generateExercise(array $config, int $seed, ?array $content = null): array
    {
        $rawList = is_array($content) && is_array($content['questions'] ?? null) ? $content['questions'] : [];
        /** @var list<array<string, mixed>> $rawQuestions */
        $rawQuestions = [];
        foreach ($rawList as $item) {
            if (is_array($item)) {
                $rawQuestions[] = ActivityPayload::assoc($item);
            }
        }
        mt_srand($seed !== 0 ? $seed : 2026);
        if (!empty($config['shuffleQuestions']) && count($rawQuestions) > 1) {
            shuffle($rawQuestions);
        }

        $safeQuestions = [];
        $answerKeys = [];
        foreach ($rawQuestions as $q) {
            $rawId = $q['id'] ?? '';
            $qid = is_scalar($rawId) ? (string) $rawId : '';
            $rawType = $q['questionType'] ?? $q['question_type'] ?? $q['type'] ?? 'single_choice';
            $qtype = is_scalar($rawType) ? (string) $rawType : 'single_choice';
            $qContent = ActivityPayload::assoc($q['content'] ?? null);
            if ($qContent === []) {
                if (isset($q['prompt']) && is_scalar($q['prompt'])) {
                    $qContent['prompt'] = (string) $q['prompt'];
                }
                if (isset($q['options']) && is_array($q['options'])) {
                    $qContent['options'] = array_map(static function (mixed $opt): mixed {
                        if (is_array($opt) && isset($opt['id']) && !isset($opt['value'])) {
                            $opt['value'] = $opt['id'];
                        }
                        return $opt;
                    }, $q['options']);
                }
                if (isset($q['items']) && is_array($q['items'])) {
                    $qContent['items'] = $q['items'];
                }
                if (isset($q['pairs']) && is_array($q['pairs'])) {
                    $qContent['pairs'] = $q['pairs'];
                }
                if (isset($q['tolerance']) && is_numeric($q['tolerance'])) {
                    $qContent['tolerance'] = max(0.0, (float) $q['tolerance']);
                }
            }
            $rawOpts = $qContent['options'] ?? null;
            if (!empty($config['shuffleOptions']) && is_array($rawOpts) && in_array($qtype, ['single_choice', 'multi_choice'], true)) {
                $opts = array_values($rawOpts);
                shuffle($opts);
                $qContent['options'] = $opts;
            }
            $pts = (int) ($q['points'] ?? $q['defaultPoints'] ?? 1);
            $tolerance = isset($q['tolerance']) && is_numeric($q['tolerance'])
                ? max(0.0, (float) $q['tolerance'])
                : (isset($qContent['tolerance']) && is_numeric($qContent['tolerance']) ? max(0.0, (float) $qContent['tolerance']) : 0.0);
            $safeQuestions[] = [
                'id' => $qid,
                'questionType' => $qtype,
                'content' => $qContent,
                'points' => $pts,
            ];
            $answerKeys[$qid] = [
                'questionType' => $qtype,
                'points' => $pts,
                'prompt' => is_scalar($qContent['prompt'] ?? null) ? (string) $qContent['prompt'] : '',
                'answerKey' => $q['answerKey'] ?? $q['answer_key'] ?? null,
                'tolerance' => $tolerance,
                'explanation' => is_scalar($q['explanation'] ?? null) ? (string) $q['explanation'] : '',
            ];
        }

        return [
            'timeLimitSeconds' => $config['timeLimitSeconds'] ?? null,
            'questions' => $safeQuestions,
            '_answerKeys' => $answerKeys,
        ];
    }

    public function evaluate(
        array $config,
        array $exercise,
        array $submission,
        array $telemetry = [],
        ?array $content = null,
    ): array {
        $keys = ActivityPayload::assoc($exercise['_answerKeys'] ?? null);
        $answers = is_array($submission['answers'] ?? null) ? ActivityPayload::assoc($submission['answers']) : $submission;
        $partialCredit = (bool) ($config['partialCredit'] ?? true);

        $rawScore = 0.0;
        $maxScore = 0.0;
        $requiresManualReview = false;
        $breakdown = [];

        foreach ($keys as $qid => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $qtype = (string) ($spec['questionType'] ?? 'single_choice');
            $pts = (float) ($spec['points'] ?? 1);
            $expected = $spec['answerKey'] ?? null;
            $actual = $answers[$qid] ?? null;
            $maxScore += $pts;

            if ($qtype === 'long_text') {
                $requiresManualReview = true;
                $breakdown[] = [
                    'questionId' => $qid,
                    'questionType' => $qtype,
                    'prompt' => is_scalar($spec['prompt'] ?? null) ? (string) $spec['prompt'] : '',
                    'pointsEarned' => 0.0,
                    'maxPoints' => $pts,
                    'pendingReview' => true,
                    'correct' => null,
                    'actual' => $actual,
                    'expected' => $expected,
                    'explanation' => is_scalar($spec['explanation'] ?? null) ? (string) $spec['explanation'] : '',
                    'comment' => null,
                ];
                continue;
            }

            $tolerance = (float) ($spec['tolerance'] ?? 0.0);
            $ratio = $this->scoreQuestion($qtype, $expected, $actual, $partialCredit, $tolerance);
            $earned = round($pts * $ratio, 2);
            $rawScore += $earned;
            $breakdown[] = [
                'questionId' => $qid,
                'questionType' => $qtype,
                'prompt' => is_scalar($spec['prompt'] ?? null) ? (string) $spec['prompt'] : '',
                'pointsEarned' => $earned,
                'maxPoints' => $pts,
                'pendingReview' => false,
                'correct' => $ratio >= 0.999,
                'partial' => $ratio > 0.0 && $ratio < 0.999,
                'actual' => $actual,
                'expected' => $expected,
                'explanation' => is_scalar($spec['explanation'] ?? null) ? (string) $spec['explanation'] : '',
                'comment' => null,
            ];
        }

        $pct = $maxScore > 0 ? round(($rawScore / $maxScore) * 100.0, 2) : 0.0;
        $passPct = (float) ($config['passingScorePercent'] ?? 60);
        $passed = !$requiresManualReview && $pct >= $passPct;

        return [
            'rawScore' => round($rawScore, 2),
            'maxScore' => round($maxScore, 2),
            'percentage' => $pct,
            'passed' => $passed,
            'requiresManualReview' => $requiresManualReview,
            'breakdown' => $breakdown,
            'metrics' => [
                'score' => round($rawScore, 2),
                'maxScore' => round($maxScore, 2),
                'percentage' => $pct,
                'questionCount' => count($keys),
            ],
            'telemetryVerified' => false,
            'feedback' => $requiresManualReview
                ? 'Submitted for teacher review of open-ended answers.'
                : "Scored {$rawScore} / {$maxScore} ({$pct}%).",
        ];
    }

    private function scoreQuestion(string $qtype, mixed $expected, mixed $actual, bool $partialCredit, float $tolerance = 0.0): float
    {
        if ($actual === null) {
            return 0.0;
        }
        return match ($qtype) {
            'single_choice' => ((string) $expected === (string) $actual) ? 1.0 : 0.0,
            'true_false' => $this->scoreTrueFalse($expected, $actual),
            'multi_choice' => $this->scoreMultiChoice(
                ActivityPayload::stringList($expected),
                ActivityPayload::stringList($actual),
                $partialCredit,
            ),
            'short_text' => $this->scoreShortText($expected, $actual),
            'numeric' => (is_numeric($expected) && is_numeric($actual) && abs((float) $expected - (float) $actual) <= max(0.0001, $tolerance)) ? 1.0 : 0.0,
            'matching' => $this->scoreMatching(
                ActivityPayload::assoc($expected),
                ActivityPayload::assoc($actual),
                $partialCredit,
            ),
            'ordering' => $this->scoreOrdering(
                ActivityPayload::stringList($expected),
                ActivityPayload::stringList($actual),
                $partialCredit,
            ),
            default => 0.0,
        };
    }

    private function scoreTrueFalse(mixed $expected, mixed $actual): float
    {
        $norm = static function (mixed $val): ?bool {
            if (is_bool($val)) {
                return $val;
            }
            if (is_string($val)) {
                $lower = strtolower(trim($val));
                if ($lower === 'true' || $lower === '1') {
                    return true;
                }
                if ($lower === 'false' || $lower === '0') {
                    return false;
                }
            }
            if (is_int($val)) {
                return $val !== 0;
            }
            return null;
        };
        $exp = $norm($expected);
        $act = $norm($actual);
        return ($exp !== null && $act !== null && $exp === $act) ? 1.0 : 0.0;
    }

    private function scoreShortText(mixed $expected, mixed $actual): float
    {
        if (!is_scalar($actual)) {
            return 0.0;
        }
        $normActual = mb_strtolower(trim((string) $actual), 'UTF-8');
        if ($normActual === '') {
            return 0.0;
        }
        if (is_array($expected)) {
            foreach ($expected as $accepted) {
                if (is_scalar($accepted) && $normActual === mb_strtolower(trim((string) $accepted), 'UTF-8')) {
                    return 1.0;
                }
            }
            return 0.0;
        }
        if (!is_scalar($expected)) {
            return 0.0;
        }
        return $normActual === mb_strtolower(trim((string) $expected), 'UTF-8') ? 1.0 : 0.0;
    }

    /** @param list<string> $expected @param list<string> $actual */
    private function scoreMultiChoice(array $expected, array $actual, bool $partialCredit): float
    {
        $exp = array_values(array_unique($expected));
        $act = array_values(array_unique($actual));
        sort($exp);
        sort($act);
        if ($exp === $act) {
            return 1.0;
        }
        if (!$partialCredit || $exp === []) {
            return 0.0;
        }
        $hits = count(array_intersect($exp, $act));
        $wrong = count(array_diff($act, $exp));
        return max(0.0, round(($hits - $wrong) / count($exp), 4));
    }

    /** @param array<array-key, mixed> $expected @param array<array-key, mixed> $actual */
    private function scoreMatching(array $expected, array $actual, bool $partialCredit): float
    {
        if ($expected === []) {
            return 0.0;
        }
        $correct = 0;
        foreach ($expected as $k => $v) {
            if (array_key_exists($k, $actual) && (string) $actual[$k] === (string) $v) {
                $correct++;
            }
        }
        if ($correct === count($expected)) {
            return 1.0;
        }
        return $partialCredit ? round($correct / count($expected), 4) : 0.0;
    }

    /** @param list<string> $expected @param list<string> $actual */
    private function scoreOrdering(array $expected, array $actual, bool $partialCredit): float
    {
        if ($expected === []) {
            return 0.0;
        }
        if ($expected === $actual) {
            return 1.0;
        }
        if (!$partialCredit) {
            return 0.0;
        }
        $posMatches = 0;
        foreach ($expected as $idx => $val) {
            if (($actual[$idx] ?? null) === $val) {
                $posMatches++;
            }
        }
        return round($posMatches / count($expected), 4);
    }

    /**
     * Validates assessment questions before publishing across all 8 supported question types.
     *
     * @param array<array-key, mixed> $questions
     */
    public static function validateQuestions(array $questions): void
    {
        if ($questions === []) {
            throw new HttpError(422, 'empty_assessment_questions');
        }

        $allowed = ['single_choice', 'multi_choice', 'true_false', 'short_text', 'long_text', 'numeric', 'matching', 'ordering'];
        $seenIds = [];
        foreach ($questions as $q) {
            if (!is_array($q)) {
                throw new HttpError(422, 'invalid_question_structure');
            }
            $rawId = $q['id'] ?? '';
            $id = is_string($rawId) ? trim($rawId) : '';
            if ($id === '' || isset($seenIds[$id])) {
                throw new HttpError(422, 'duplicate_or_empty_question_id');
            }
            $seenIds[$id] = true;

            $type = (string) ($q['questionType'] ?? $q['question_type'] ?? $q['type'] ?? '');
            if (!in_array($type, $allowed, true)) {
                throw new HttpError(422, 'unsupported_question_type');
            }

            $qContent = is_array($q['content'] ?? null) ? $q['content'] : [];
            $rawPrompt = $qContent['prompt'] ?? $q['prompt'] ?? '';
            $prompt = is_string($rawPrompt) ? trim($rawPrompt) : '';
            if ($prompt === '' || mb_strlen($prompt, 'UTF-8') > 4000) {
                throw new HttpError(422, 'invalid_question_prompt');
            }

            $pts = $q['points'] ?? $q['defaultPoints'] ?? 1;
            if (!is_numeric($pts) || (int) $pts < 1 || (int) $pts > 100) {
                throw new HttpError(422, 'invalid_question_points');
            }

            $answerKey = $q['answerKey'] ?? $q['answer_key'] ?? null;

            if ($type === 'single_choice' || $type === 'multi_choice') {
                $options = $qContent['options'] ?? $q['options'] ?? [];
                if (!is_array($options) || count($options) < 2) {
                    throw new HttpError(422, 'insufficient_options');
                }
                $seenOptIds = [];
                foreach ($options as $opt) {
                    if (is_string($opt)) {
                        $optId = trim($opt);
                        $label = $optId;
                    } elseif (is_array($opt)) {
                        $optId = trim((string) ($opt['id'] ?? $opt['value'] ?? ''));
                        $label = trim((string) ($opt['label'] ?? $opt['value'] ?? $opt['id'] ?? ''));
                    } else {
                        throw new HttpError(422, 'invalid_option_structure');
                    }
                    if ($optId === '' || $label === '' || isset($seenOptIds[$optId])) {
                        throw new HttpError(422, 'invalid_option_id_or_label');
                    }
                    $seenOptIds[$optId] = true;
                }
                if ($type === 'single_choice') {
                    if (!is_string($answerKey) || !isset($seenOptIds[$answerKey])) {
                        throw new HttpError(422, 'invalid_answer_key');
                    }
                } else {
                    if (!is_array($answerKey) || $answerKey === []) {
                        throw new HttpError(422, 'invalid_answer_key');
                    }
                    foreach ($answerKey as $k) {
                        if (!is_string($k) || !isset($seenOptIds[$k])) {
                            throw new HttpError(422, 'invalid_answer_key');
                        }
                    }
                }
            } elseif ($type === 'true_false') {
                if (!is_bool($answerKey) && $answerKey !== 'true' && $answerKey !== 'false') {
                    throw new HttpError(422, 'invalid_answer_key');
                }
            } elseif ($type === 'numeric') {
                if (!is_numeric($answerKey)) {
                    throw new HttpError(422, 'invalid_answer_key');
                }
                $tol = $q['tolerance'] ?? $qContent['tolerance'] ?? 0;
                if (!is_numeric($tol) || (float) $tol < 0) {
                    throw new HttpError(422, 'invalid_numeric_tolerance');
                }
            } elseif ($type === 'short_text') {
                if (is_string($answerKey)) {
                    if (trim($answerKey) === '') {
                        throw new HttpError(422, 'invalid_answer_key');
                    }
                } elseif (is_array($answerKey) && $answerKey !== []) {
                    foreach ($answerKey as $ak) {
                        if (!is_string($ak) || trim($ak) === '') {
                            throw new HttpError(422, 'invalid_answer_key');
                        }
                    }
                } else {
                    throw new HttpError(422, 'invalid_answer_key');
                }
            } elseif ($type === 'matching') {
                $pairs = $qContent['pairs'] ?? $q['pairs'] ?? [];
                if (!is_array($pairs) || count($pairs) < 2) {
                    throw new HttpError(422, 'insufficient_matching_pairs');
                }
                $seenLeft = [];
                foreach ($pairs as $pair) {
                    if (!is_array($pair)) {
                        throw new HttpError(422, 'invalid_matching_pair');
                    }
                    $left = trim((string) ($pair['left'] ?? ''));
                    $right = trim((string) ($pair['right'] ?? ''));
                    if ($left === '' || $right === '' || isset($seenLeft[$left])) {
                        throw new HttpError(422, 'invalid_matching_pair');
                    }
                    $seenLeft[$left] = $right;
                }
                if (!is_array($answerKey) || count($answerKey) !== count($seenLeft)) {
                    throw new HttpError(422, 'invalid_answer_key');
                }
                foreach ($seenLeft as $left => $right) {
                    if (!array_key_exists($left, $answerKey) || trim((string) $answerKey[$left]) === '') {
                        throw new HttpError(422, 'invalid_answer_key');
                    }
                }
            } elseif ($type === 'ordering') {
                $items = $qContent['items'] ?? $q['items'] ?? [];
                if (!is_array($items) || count($items) < 2) {
                    throw new HttpError(422, 'insufficient_ordering_items');
                }
                foreach ($items as $item) {
                    if (!is_string($item) || trim($item) === '') {
                        throw new HttpError(422, 'invalid_ordering_item');
                    }
                }
                if (!is_array($answerKey) || count($answerKey) !== count($items)) {
                    throw new HttpError(422, 'invalid_answer_key');
                }
            }
        }
    }
}
