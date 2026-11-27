<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Plugins;

use App\Activity\Application\ActivityPluginInterface;

final class TypingActivityPlugin implements ActivityPluginInterface
{
    public function code(): string
    {
        return 'platform-typing';
    }

    public function version(): int
    {
        return 3;
    }

    public function title(): string
    {
        return 'Typing Arena 4.0';
    }

    public function category(): string
    {
        return 'typing';
    }

    public function capabilities(): array
    {
        return ['supportsDrafts', 'supportsRealtime', 'supportsTimer', 'supportsPractice'];
    }

    public function configSchema(): array
    {
        return [
            'mode' => ['type' => 'enum', 'values' => ['words', 'sentences', 'numbers', 'code', 'quote', 'zen', 'accuracy', 'custom'], 'default' => 'words'],
            'submode' => ['type' => 'enum', 'values' => ['time', 'words', 'quote', 'zen', 'custom'], 'default' => 'time'],
            'timeLimitSeconds' => ['type' => 'integer', 'min' => 10, 'max' => 600, 'default' => 60],
            'wordCount' => ['type' => 'integer', 'min' => 5, 'max' => 250, 'default' => 30],
            'difficulty' => ['type' => 'integer', 'min' => 1, 'max' => 3, 'default' => 1],
            'targetWpm' => ['type' => 'integer', 'min' => 0, 'max' => 300, 'default' => 0],
            'minAccuracy' => ['type' => 'integer', 'min' => 0, 'max' => 100, 'default' => 85],
            'language' => ['type' => 'enum', 'values' => ['en', 'ru'], 'default' => 'en'],
            'punctuation' => ['type' => 'boolean', 'default' => false],
            'numbers' => ['type' => 'boolean', 'default' => false],
            'customText' => ['type' => 'string', 'maxLength' => 4000, 'default' => ''],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'mode' => 'words',
            'submode' => 'time',
            'timeLimitSeconds' => 60,
            'wordCount' => 30,
            'difficulty' => 1,
            'targetWpm' => 0,
            'minAccuracy' => 85,
            'language' => 'en',
            'punctuation' => false,
            'numbers' => false,
            'customText' => '',
        ];
    }

    public function safeParticipantConfigKeys(): array
    {
        return [
            'language',
            'mode',
            'submode',
            'timeLimitSeconds',
            'wordCount',
            'difficulty',
            'punctuation',
            'numbers',
            'customText',
        ];
    }

    public function generateExercise(array $config, int $seed, ?array $content = null): array
    {
        $rawContentCustom = is_array($content) ? ($content['customText'] ?? null) : null;
        $rawConfigCustom = $config['customText'] ?? null;
        $customText = '';
        if (is_string($rawContentCustom) && trim($rawContentCustom) !== '') {
            $customText = trim($rawContentCustom);
        } elseif (is_string($rawConfigCustom) && trim($rawConfigCustom) !== '') {
            $customText = trim($rawConfigCustom);
        }

        $mode = (string) ($config['mode'] ?? 'words');
        $submode = (string) ($config['submode'] ?? 'time');
        $difficulty = max(1, min(3, (int) ($config['difficulty'] ?? 1)));
        $wordCount = max(5, min(250, (int) ($config['wordCount'] ?? 30)));
        $timeLimitSeconds = max(10, min(600, (int) ($config['timeLimitSeconds'] ?? 60)));
        $language = ($config['language'] ?? 'en') === 'ru' ? 'ru' : 'en';
        $punctuation = !empty($config['punctuation']);
        $numbers = !empty($config['numbers']);

        if ($mode === 'zen' || $submode === 'zen') {
            $promptText = '';
        } elseif (($submode === 'custom' || $mode === 'custom') && $customText !== '') {
            $promptText = preg_replace('/\s+/u', ' ', $customText) ?? $customText;
        } elseif ($customText !== '' && is_string($rawContentCustom) && trim($rawContentCustom) !== '') {
            $promptText = preg_replace('/\s+/u', ' ', $customText) ?? $customText;
        } else {
            mt_srand($seed !== 0 ? $seed : 1337);
            if ($mode === 'code') {
                $pool = self::codePool($difficulty);
                $parts = [];
                $repeat = $submode === 'time' ? max(4, (int) ceil($timeLimitSeconds / 15)) : ($difficulty + 1);
                for ($i = 0; $i < $repeat; $i++) {
                    $parts[] = $pool[mt_rand(0, count($pool) - 1)];
                }
                $promptText = implode(' ', $parts);
            } elseif ($mode === 'numbers') {
                $pool = self::numbersPool($difficulty);
                $parts = [];
                $repeat = $submode === 'time' ? max(4, (int) ceil($timeLimitSeconds / 12)) : ($difficulty + 2);
                for ($i = 0; $i < $repeat; $i++) {
                    $parts[] = $pool[mt_rand(0, count($pool) - 1)];
                }
                $promptText = implode(' ', $parts);
            } elseif ($submode === 'quote' || $mode === 'quote' || $mode === 'sentences' || $mode === 'accuracy') {
                $pool = self::quotePool($language, $difficulty);
                if ($submode === 'quote' || $mode === 'quote') {
                    $promptText = $pool[mt_rand(0, count($pool) - 1)];
                } else {
                    $parts = [];
                    $repeat = $submode === 'time' ? max(4, (int) ceil($timeLimitSeconds / 15)) : ($difficulty + 1);
                    for ($i = 0; $i < $repeat; $i++) {
                        $parts[] = $pool[mt_rand(0, count($pool) - 1)];
                    }
                    $promptText = implode(' ', $parts);
                }
            } else {
                $pool = self::wordPool($language, $difficulty);
                $targetWords = $submode === 'time'
                    ? max(60, (int) ceil($timeLimitSeconds * 2.2))
                    : $wordCount;

                $words = [];
                $capitalizeNext = $punctuation;
                $punctMarks = [',', '.', ';', ':', '!', '?'];
                $numTokens = ['12', '42', '100', '2026', '75%', '3.14', '80', '15', '99', '24'];

                for ($i = 0; $i < $targetWords; $i++) {
                    if ($numbers && $i > 0 && mt_rand(1, 100) <= 18) {
                        $word = $numTokens[mt_rand(0, count($numTokens) - 1)];
                    } else {
                        $word = $pool[mt_rand(0, count($pool) - 1)];
                    }

                    if ($capitalizeNext) {
                        $first = mb_substr($word, 0, 1, 'UTF-8');
                        $rest = mb_substr($word, 1, null, 'UTF-8');
                        $word = mb_strtoupper($first, 'UTF-8') . $rest;
                        $capitalizeNext = false;
                    }

                    if ($punctuation && $i < $targetWords - 1 && mt_rand(1, 100) <= 22) {
                        $mark = $punctMarks[mt_rand(0, count($punctMarks) - 1)];
                        $word .= $mark;
                        if (in_array($mark, ['.', '!', '?'], true)) {
                            $capitalizeNext = true;
                        }
                    } elseif ($punctuation && $i === $targetWords - 1 && !preg_match('/[.!?]$/u', $word)) {
                        $word .= '.';
                    }

                    $words[] = $word;
                }
                $promptText = implode(' ', $words);
            }
        }

        return [
            'mode' => $mode,
            'submode' => $submode,
            'language' => $language,
            'difficulty' => $difficulty,
            'punctuation' => $punctuation,
            'numbers' => $numbers,
            'promptText' => $promptText,
            'timeLimitSeconds' => $submode === 'time' ? $timeLimitSeconds : 0,
            'wordCount' => $wordCount,
        ];
    }

    public function evaluate(
        array $config,
        array $exercise,
        array $submission,
        array $telemetry = [],
        ?array $content = null,
    ): array {
        $prompt = (string) ($exercise['promptText'] ?? '');
        $typed = (string) ($submission['typedText'] ?? $submission['text'] ?? '');
        $submode = (string) ($exercise['submode'] ?? $config['submode'] ?? 'time');
        $mode = (string) ($exercise['mode'] ?? $config['mode'] ?? 'words');
        $isZen = $mode === 'zen' || $submode === 'zen' || $prompt === '';

        $rawDuration = (float) ($submission['elapsedSeconds'] ?? $telemetry['elapsedSeconds'] ?? 1.0);
        $durationSec = max(1.0, $rawDuration);
        if ($submode === 'time' && !empty($exercise['timeLimitSeconds'])) {
            $durationSec = min($durationSec, (float) $exercise['timeLimitSeconds'] + 5.0);
        }

        $expectedChars = self::splitGraphemes($prompt);
        $actualChars = self::splitGraphemes($typed);
        $actualLen = count($actualChars);
        $expectedLen = count($expectedChars);

        if ($isZen) {
            $correctChars = $actualLen;
            $errors = 0;
            $accuracy = $actualLen > 0 ? 100.0 : 0.0;
        } else {
            $correctChars = 0;
            $errors = 0;
            $limit = min($actualLen, $expectedLen);
            for ($i = 0; $i < $limit; $i++) {
                if ($expectedChars[$i] === $actualChars[$i]) {
                    $correctChars++;
                } else {
                    $errors++;
                }
            }
            if ($actualLen > $expectedLen) {
                $errors += $actualLen - $expectedLen;
            }
            $accuracy = $actualLen > 0 ? round(($correctChars / $actualLen) * 100.0, 2) : 0.0;
        }

        $minutes = $durationSec / 60.0;
        $netWpm = round(($correctChars / 5.0) / $minutes, 1);
        $rawWpm = round(($actualLen / 5.0) / $minutes, 1);
        $cpm = (int) round($correctChars / $minutes);

        $minAcc = (float) ($config['minAccuracy'] ?? 85);
        $targetWpm = (float) ($config['targetWpm'] ?? 0);

        $passed = $actualLen > 0 && $accuracy >= $minAcc && ($targetWpm <= 0 || $netWpm >= $targetWpm);
        $corrections = max(0, (int) ($telemetry['backspaceCount'] ?? $telemetry['corrections'] ?? 0));
        $inputErrors = max($errors, (int) ($telemetry['inputErrors'] ?? $errors));
        $consistency = isset($telemetry['consistency']) ? max(0.0, min(100.0, round((float) $telemetry['consistency'], 1))) : null;
        $wpmSamples = is_array($telemetry['wpmSamples'] ?? null) ? array_slice($telemetry['wpmSamples'], 0, 600) : [];

        return [
            'rawScore' => $netWpm,
            'maxScore' => max($netWpm, $targetWpm > 0 ? $targetWpm : $netWpm, 1.0),
            'percentage' => min(100.0, round($accuracy, 2)),
            'passed' => $passed,
            'requiresManualReview' => false,
            'breakdown' => [],
            'metrics' => [
                'wpm' => $netWpm,
                'rawWpm' => $rawWpm,
                'cpm' => $cpm,
                'accuracy' => $accuracy,
                'correctChars' => $correctChars,
                'totalTypedChars' => $actualLen,
                'errorCount' => $errors,
                'inputErrors' => $inputErrors,
                'corrections' => $corrections,
                'elapsedSeconds' => round($durationSec, 2),
                'consistency' => $consistency,
                'language' => (string) ($exercise['language'] ?? $config['language'] ?? 'en'),
                'mode' => $mode,
                'submode' => $submode,
                'wpmSamples' => $wpmSamples,
            ],
            'telemetryVerified' => false,
            'feedback' => $passed
                ? "Target met: {$netWpm} WPM ({$cpm} CPM) at {$accuracy}% accuracy."
                : "Completed at {$netWpm} WPM ({$cpm} CPM, {$accuracy}% accuracy).",
        ];
    }

    /** @return list<string> */
    private static function splitGraphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (preg_match_all('/\X/u', $text, $matches) !== false && is_array($matches[0] ?? null)) {
            return $matches[0];
        }
        return mb_str_split($text, 1, 'UTF-8');
    }

    /** @return list<string> */
    private static function wordPool(string $language, int $difficulty): array
    {
        if ($language === 'ru') {
            return match ($difficulty) {
                1 => [
                    'дом', 'кот', 'лес', 'мир', 'сад', 'чай', 'стол', 'окно', 'море', 'небо',
                    'утро', 'книга', 'лампа', 'река', 'школа', 'слово', 'день', 'время', 'город', 'путь',
                    'свет', 'звук', 'рука', 'шаг', 'план', 'урок', 'лист', 'ветер', 'поле', 'берег',
                ],
                2 => [
                    'платформа', 'алгоритм', 'данные', 'проект', 'система', 'модуль', 'скорость', 'точность',
                    'прогресс', 'результат', 'обучение', 'память', 'логика', 'поток', 'сервер', 'клиент',
                    'версия', 'проверка', 'клавиатура', 'решение', 'программа', 'учитель', 'ученик', 'команда',
                    'рисунок', 'задача', 'практика', 'анализ', 'таблица', 'правило',
                ],
                default => [
                    'последовательность', 'программирование', 'закономерность', 'исследование',
                    'ответственность', 'воображение', 'синхронизация', 'архитектура', 'производительность',
                    'конфигурация', 'идентификация', 'достоверность', 'универсальный', 'интерфейс',
                    'масштабирование', 'совместимость', 'взаимодействие', 'оптимизация', 'стабильность',
                ],
            };
        }

        return match ($difficulty) {
            1 => [
                'cat', 'dog', 'sun', 'sky', 'tree', 'book', 'door', 'home', 'blue', 'green',
                'learn', 'play', 'river', 'cloud', 'time', 'word', 'line', 'step', 'code', 'fast',
                'hand', 'desk', 'light', 'sound', 'path', 'work', 'plan', 'day', 'star', 'wind',
            ],
            2 => [
                'platform', 'signal', 'vector', 'module', 'stream', 'window', 'packet', 'studio',
                'metric', 'syntax', 'layout', 'design', 'engine', 'kernel', 'branch', 'commit',
                'server', 'client', 'worker', 'thread', 'matrix', 'puzzle', 'result', 'fluent',
                'steady', 'rhythm', 'keyboard', 'practice', 'teacher', 'student', 'accuracy',
            ],
            default => [
                'sequence', 'programming', 'investigation', 'responsibility', 'imagination',
                'architecture', 'synchronization', 'compatibility', 'configuration', 'observability',
                'deterministic', 'performance', 'implementation', 'verification', 'segmentation',
                'infrastructure', 'consistency', 'accessibility', 'optimization', 'telemetry',
            ],
        };
    }

    /** @return list<string> */
    private static function quotePool(string $language, int $difficulty): array
    {
        if ($language === 'ru') {
            return match ($difficulty) {
                1 => [
                    'У нас есть план. Мы учимся каждый день и шаг за шагом становимся быстрее.',
                    'Тихий ветер качает ветки. На столе открыта книга, а за окном светит утреннее солнце.',
                ],
                2 => [
                    'Проверь порядок действий, затем аккуратно запиши результат без лишней спешки.',
                    'Регулярная практика развивает точность раньше скорости: ровный ритм важнее рывков.',
                ],
                default => [
                    'Чёткая архитектура отделяет стабильные правила домена от сменных адаптеров транспорта и интерфейса.',
                    'В 09:30 команда проверила телеметрию, исправила две ошибки сегментации и запустила контрольный тест.',
                ],
            };
        }

        return match ($difficulty) {
            1 => [
                'We learn a little every day. Steady practice turns simple steps into lasting skill.',
                'The sun is bright above the river, and a quiet breeze moves across the open field.',
            ],
            2 => [
                'Consistent practice builds accuracy before raw speed: focus on steady rhythm and minimal corrections.',
                'Check each step carefully, keep your posture relaxed, and trust muscle memory over frantic haste.',
            ],
            default => [
                'Clear architecture separates stable domain rules from replaceable transport adapters so teams can evolve fast without breaking correctness.',
                'At 09:30, the engineering team verified Unicode grapheme segmentation, fixed edge cases, and shipped the unified arena.',
            ],
        };
    }

    /** @return list<string> */
    private static function numbersPool(int $difficulty): array
    {
        return match ($difficulty) {
            1 => ['12 34 56 78 90 123 456 789 102 345', '10 20 30 40 50 60 70 80 90 100'],
            2 => ['42 + 18 * 3 - 9 = 87; [0.25, 0.50, 0.75, 1.00]', '12, 24; 36: 48. 50! 62? (74) [86] 98%'],
            default => ['2026-11-27 09:30:45 +7 (343) 123-45-67 3.1415 25% #42', 'UUID: 01a21144-e1cb-7993 | TCP: 5173 -> 8083 (98.5%)'],
        };
    }

    /** @return list<string> */
    private static function codePool(int $difficulty): array
    {
        return match ($difficulty) {
            1 => ['let count = 5; let total = count + 2;', 'const name = "Platform"; console.log(name);'],
            2 => ['const result = items.filter((x) => x.active).map((x) => x.id);', 'for (let i = 0; i < queue.length; i += 1) { processItem(queue[i]); }'],
            default => ['function clamp(value: number, min: number, max: number): number { return Math.min(max, Math.max(min, value)); }', 'async function syncOutbox(batch: EventEnvelope[]): Promise<void> { await client.publish(batch); }'],
        };
    }
}
