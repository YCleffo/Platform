<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Education\Activity\ActivityPluginInterface;

final class TypingActivityHandler implements ActivityPluginInterface
{
    public function code(): string
    {
        return 'platform-typing';
    }

    public function version(): string
    {
        return '3.0.0';
    }

    public function title(): string
    {
        return 'Typing 3.0';
    }

    public function description(): string
    {
        return 'Adaptive touch typing and speed trainer with multi-mode text engines and real-time telemetry.';
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => ['type' => 'string', 'enum' => ['ru', 'en']],
                'durationSeconds' => ['type' => 'integer', 'enum' => [15, 30, 60]],
                'mode' => ['type' => 'string', 'enum' => ['words', 'sentences', 'numbers', 'code', 'accuracy']],
                'difficulty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
                'minAccuracy' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'targetWpm' => ['type' => 'number', 'minimum' => 0, 'maximum' => 500],
                'maxAttempts' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'language' => 'en',
            'durationSeconds' => 30,
            'mode' => 'words',
            'difficulty' => 1,
            'minAccuracy' => 0,
            'targetWpm' => 0,
            'maxAttempts' => 20,
        ];
    }
    public function validateConfig(array $config): array
    {
        ActivityHandlerRegistry::only($config, ['language','durationSeconds','mode','difficulty','minAccuracy','targetWpm','maxAttempts']);
        $out = array_replace(['language' => 'en','durationSeconds' => 30,'mode' => 'words','difficulty' => 1,'minAccuracy' => 0,'targetWpm' => 0,'maxAttempts' => 20], $config);
        if (!in_array($out['language'], ['ru','en'], true) || !in_array($out['durationSeconds'], [15,30,60], true)
            || !in_array($out['mode'], ['words','sentences','numbers','code','accuracy'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        ActivityHandlerRegistry::integer($out['difficulty'], 1, 3);
        ActivityHandlerRegistry::integer($out['maxAttempts'], 1, 20);
        foreach (['minAccuracy' => 100,'targetWpm' => 500] as $key => $max) {
            if ((!is_int($out[$key]) && !is_float($out[$key])) || !is_finite((float) $out[$key]) || $out[$key] < 0 || $out[$key] > $max) {
                throw new EducationError(422, 'invalid_input');
            }
        }
        if ($out['mode'] === 'accuracy' && !array_key_exists('minAccuracy', $config)) {
            $out['minAccuracy'] = 95;
        }
        return $out;
    }

    public function createExercise(array $config, ?array $set = null): array
    {
        $texts = $set === null ? TypingExerciseSets::texts($config['language'], $config['mode'], $config['difficulty'])
            : ActivityHandlerRegistry::json($set['content'] ?? []);
        if ($texts === [] || !array_is_list($texts) || count(array_filter($texts, 'is_string')) !== count($texts)) {
            throw new EducationError(503, 'exercise_unavailable');
        }
        $random = new \Random\Randomizer();
        $maximum = (int) ceil(500 * 5 * $config['durationSeconds'] / 60);
        $target = $maximum - 100;
        $parts = [];
        $length = 0;
        while ($length < $target) {
            $part = $texts[$random->getInt(0, count($texts) - 1)];
            if ($part === '') {
                throw new EducationError(503, 'exercise_unavailable');
            }
            $parts[] = $part;
            $length += mb_strlen($part) + 1;
        }
        return ['text' => mb_substr(implode(' ', $parts), 0, $maximum - 32, 'UTF-8'), 'version' => (int) ($set['version'] ?? TypingExerciseSets::VERSION), 'setCode' => $set['code'] ?? 'typing-v2'];
    }

    public function publicExercise(array $attempt): array
    {
        $config = $this->config($attempt);
        $payload = ActivityHandlerRegistry::json($attempt['exercisePayload'] ?? []);
        return ['text' => (string) ($attempt['exercise'] ?? ''), 'version' => (int) ($payload['version'] ?? 1),
            'durationSeconds' => $config['durationSeconds'],'language' => $config['language'],'mode' => $config['mode'],'difficulty' => $config['difficulty']];
    }

    public function validateState(array $attempt, array $state): array
    {
        ActivityHandlerRegistry::only($state, ['typed','corrections','inputErrors']);
        $config = $this->config($attempt);
        $this->typed($state['typed'] ?? '', $config['durationSeconds']);
        foreach (['corrections','inputErrors'] as $key) {
            if (isset($state[$key])) {
                ActivityHandlerRegistry::integer($state[$key], 0, 100000);
            }
        }
        return $state;
    }

    public function evaluate(array $attempt, array $input): array
    {
        ActivityHandlerRegistry::only($input, ['typed','durationSeconds','corrections','inputErrors']);
        $config = $this->config($attempt);
        if (!is_int($input['durationSeconds'] ?? null) || $input['durationSeconds'] !== $config['durationSeconds']) {
            throw new EducationError(422, 'invalid_input');
        }
        $typed = $this->typed($input['typed'] ?? null, $config['durationSeconds']);
        foreach (['corrections','inputErrors'] as $key) {
            if (isset($input[$key])) {
                ActivityHandlerRegistry::integer($input[$key], 0, 100000);
            }
        }
        $reference = mb_str_split((string) ($attempt['exercise'] ?? ''), 1, 'UTF-8');
        $chars = mb_str_split($typed, 1, 'UTF-8');
        $correct = 0;
        foreach ($chars as $i => $char) {
            if (isset($reference[$i]) && $reference[$i] === $char) {
                $correct++;
            }
        }
        $count = count($chars);
        $accuracy = $count > 0 ? round($correct / $count * 100, 2) : 0.0;
        $wpm = round($correct / 5 / ($config['durationSeconds'] / 60), 1);
        // "keystrokes" is retained solely for compatibility; it counts final text characters.
        $result = ['durationSeconds' => $config['durationSeconds'],'language' => $config['language'],'mode' => $config['mode'],
            'difficulty' => $config['difficulty'],'keystrokes' => $count,'typedCharacters' => $count,'correctChars' => $correct,
            'errors' => $count - $correct,'wpm' => $wpm,'accuracy' => $accuracy,
            'passed' => $count > 0 && $accuracy >= $config['minAccuracy'] && $wpm >= $config['targetWpm'],
            'corrections' => $input['corrections'] ?? null,'inputErrors' => $input['inputErrors'] ?? null,'telemetryVerified' => false];
        $canonical = ['typed' => $typed,'durationSeconds' => $config['durationSeconds'],'corrections' => $input['corrections'] ?? null,'inputErrors' => $input['inputErrors'] ?? null];
        return ['type' => 'typing','version' => 2,'metrics' => $result,'requestHash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'evidence' => ['typedSha256' => hash('sha256', $typed),'typedLength' => $count,'durationSeconds' => $config['durationSeconds']]];
    }

    private function config(array $attempt): array
    {
        $exercise = ActivityHandlerRegistry::json($attempt['exercisePayload'] ?? []);
        return $this->validateConfig($exercise['configSnapshot'] ?? ActivityHandlerRegistry::json($attempt['config'] ?? []));
    }

    private function typed(mixed $value, int $duration): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > (int) ceil(500 * 5 * $duration / 60)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new EducationError(422, 'invalid_input');
        }
        return $value;
    }
}
