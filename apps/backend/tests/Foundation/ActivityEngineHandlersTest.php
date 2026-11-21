<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Application\EducationError;
use App\Education\Application\LogicActivityHandler;
use App\Education\Application\TypingActivityHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure calculation tests complement the transactional production service tests. */
final class ActivityEngineHandlersTest extends TestCase
{
    public function testTypingFinalTextMetricsAreUnicodeAwareAndTelemetryNeverChangesAwards(): void
    {
        $handler = new TypingActivityHandler();
        $attempt = ['config' => ['durationSeconds' => 15, 'language' => 'ru'], 'exercise' => 'абв гд'];
        $verified = $handler->evaluate($attempt, ['typed' => 'абX гд!', 'durationSeconds' => 15]);
        $metrics = $verified['metrics'];
        self::assertSame(7, $metrics['typedCharacters']);
        self::assertSame(7, $metrics['keystrokes']);
        self::assertSame(5, $metrics['correctChars']);
        self::assertSame(2, $metrics['errors']);
        self::assertEquals(4.0, $metrics['wpm']);
        self::assertEquals(71.43, $metrics['accuracy']);
        self::assertFalse($metrics['telemetryVerified']);
        $telemetry = $handler->evaluate($attempt, ['typed' => 'абX гд!', 'durationSeconds' => 15, 'corrections' => 99999, 'inputErrors' => 99999]);
        foreach (['typedCharacters', 'correctChars', 'wpm', 'accuracy', 'passed', 'errors'] as $field) {
            self::assertSame($metrics[$field], $telemetry['metrics'][$field]);
        }
        self::assertNotSame($verified['requestHash'], $telemetry['requestHash'], 'Changed input evidence must conflict on replay.');
        $empty = $handler->evaluate($attempt, ['typed' => '', 'durationSeconds' => 15]);
        self::assertFalse($empty['metrics']['passed']);
        self::assertEquals(0, $empty['metrics']['accuracy']);
    }

    public static function invalidTypingFinish(): iterable
    {
        yield 'array text' => [['typed' => [], 'durationSeconds' => 15]];
        yield 'null text' => [['typed' => null, 'durationSeconds' => 15]];
        yield 'string time' => [['typed' => 'abc', 'durationSeconds' => '15']];
        yield 'wrong time' => [['typed' => 'abc', 'durationSeconds' => 30]];
        yield 'control character' => [['typed' => "abc\0", 'durationSeconds' => 15]];
        yield 'malformed UTF8' => [['typed' => "\xC3\x28", 'durationSeconds' => 15]];
        yield 'unbounded text' => [['typed' => str_repeat('a', 626), 'durationSeconds' => 15]];
        yield 'negative telemetry' => [['typed' => 'abc', 'durationSeconds' => 15, 'corrections' => -1]];
        yield 'client metric' => [['typed' => 'abc', 'durationSeconds' => 15, 'wpm' => 9999]];
    }

    #[DataProvider('invalidTypingFinish')]
    public function testTypingNeverCastsMalformedEvidenceIntoAValidResult(array $input): void
    {
        $this->expectException(EducationError::class);
        (new TypingActivityHandler())->evaluate(['config' => ['durationSeconds' => 15, 'language' => 'en'], 'exercise' => 'abc'], $input);
    }

    public static function localesAndLevels(): iterable
    {
        foreach (['ru', 'en'] as $language) {
            foreach ([1, 2, 3] as $difficulty) {
                yield "$language/$difficulty" => [$language, $difficulty];
            }
        }
    }

    #[DataProvider('localesAndLevels')]
    public function testEveryLogicMechanicIsPlayableAndAnswersRemainPrivate(string $language, int $difficulty): void
    {
        $handler = new LogicActivityHandler();
        $config = $handler->validateConfig(['language' => $language, 'difficulty' => $difficulty, 'roundCount' => 5]);
        $private = $handler->createExercise($config);
        $attempt = ['config' => $config, 'exercisePayload' => $private];
        $public = $handler->publicExercise($attempt);
        self::assertSame(['sequence', 'pattern', 'logic', 'order', 'algorithm'], array_column($public['rounds'], 'kind'));
        $answers = [];
        foreach ($private['rounds'] as $round) {
            if ($language === 'ru') {
                self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $round['prompt'], 'Russian prompts were corrupted during file creation.');
            }
            self::assertDoesNotMatchRegularExpression('/\?{2,}/', $round['prompt']);
            self::assertDoesNotMatchRegularExpression('/\?{2,}/', $round['explanation']);
            self::assertContains($round['correctOptionId'], array_column($round['options'], 'id'));
            self::assertNotSame('', $round['explanation']);
            if ($round['kind'] !== 'logic') {
                preg_match_all('/\d+/', $round['prompt'], $matches);
                $numbers = array_map('intval', $matches[0]);
                $expected = match ($round['kind']) {
                    'sequence' => $numbers[3] + ($numbers[1] - $numbers[0]),
                    'pattern' => $numbers[2] * intdiv($numbers[1], $numbers[0]),
                    'order' => $numbers[0] + $numbers[1] * $numbers[2],
                    'algorithm' => $numbers[0] + $numbers[1] * $numbers[2],
                };
                $labels = array_column($round['options'], 'label', 'id');
                self::assertSame((string) $expected, $labels[$round['correctOptionId']], 'The server answer key does not solve the displayed question.');
                if ($round['kind'] === 'order') {
                    self::assertMatchesRegularExpression('/\d+\s*[×*]\s*\d+/u', $round['prompt'], 'The order-of-operations task must show its multiplication operator.');
                }
            }
            $answers[] = ['roundId' => $round['id'], 'optionId' => $round['correctOptionId']];
        }
        array_walk_recursive($public, static function (mixed $value, string|int $key): void {
            self::assertNotContains($key, ['correctOptionId', 'explanation']);
        });
        $evaluated = $handler->evaluate($attempt, ['answers' => $answers]);
        self::assertSame(5, $evaluated['metrics']['correctAnswers']);
        self::assertSame(0, $evaluated['metrics']['errors']);
        self::assertEquals(100, $evaluated['metrics']['accuracy']);
        self::assertTrue($evaluated['metrics']['passed']);
        self::assertSame($evaluated['requestHash'], $handler->evaluate($attempt, ['answers' => array_reverse($answers)])['requestHash'], 'Equivalent answer order should be idempotent.');
        $answers[0]['optionId'] = array_values(array_filter(array_column($private['rounds'][0]['options'], 'id'), static fn(string $id): bool => $id !== $private['rounds'][0]['correctOptionId']))[0];
        $wrong = $handler->evaluate($attempt, ['answers' => $answers]);
        self::assertSame(4, $wrong['metrics']['correctAnswers']);
        self::assertSame(1, $wrong['metrics']['errors']);
    }

    public function testIncompleteLogicAnswersMayBeSavedButCannotFinish(): void
    {
        $handler = new LogicActivityHandler();
        $config = $handler->validateConfig([]);
        $attempt = ['config' => $config, 'exercisePayload' => $handler->createExercise($config)];
        self::assertSame(['answers' => [], 'currentRound' => 0], $handler->validateState($attempt, ['answers' => []]));
        $this->expectException(EducationError::class);
        $handler->evaluate($attempt, ['answers' => []]);
    }
}
