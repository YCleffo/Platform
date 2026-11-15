<?php

declare(strict_types=1);

namespace App\Education\Application;

/** Immutable v2 fallback and migration seed; published sets are never updated in place. */
final class TypingExerciseSets
{
    public const VERSION = 2;

    public static function texts(string $language, string $mode, int $difficulty): array
    {
        if ($mode === 'numbers') return match ($difficulty) {
            1 => ['12 34 56 78 90 123 456 789 102 345'],
            2 => ['12, 24; 36: 48. 50! 62? (74) [86] 98%'],
            default => ['2026-11-14 09:30:45 +7 (343) 123-45-67 3.1415 25% #42 @home'],
        };
        if ($mode === 'code') return match ($difficulty) {
            1 => ['let count = 5; let total = count + 2;', 'const name = "Platform"; print(name);'],
            2 => ['for (let i = 0; i < 5; i++) { total += i; }', 'if (score >= 10) { print("Ready!"); }'],
            default => ['function sum(items) { return items.reduce((a, b) => a + b, 0); }', 'const even = values.filter(value => value % 2 === 0);'],
        };
        if ($mode === 'sentences' || $mode === 'accuracy') {
            if ($language === 'ru') return match ($difficulty) {
                1 => ['У нас есть план. Мы учимся каждый день.', 'Тихий ветер качает ветки. Кот смотрит в окно.'],
                2 => ['Проверь порядок действий, затем запиши результат.', 'На экране появилась задача: найди верный ответ!'],
                default => ['Почему алгоритм завершился? Спросил ученик, проверяя условие.', 'В 09:30 команда проверила код, исправила ошибки и начала новый опыт.'],
            };
            return match ($difficulty) {
                1 => ['We learn a little every day. The sun is bright.', 'A small robot can follow a simple plan.'],
                2 => ['Check each step, then write down the result.', 'The screen shows a question: can you solve it?'],
                default => ['"Why did the algorithm stop?" asked the student.', 'At 09:30, the team checked its code, fixed three errors, and tried again.'],
            };
        }
        if ($language === 'ru') return match ($difficulty) {
            1 => ['дом кот лес мир сад чай стол окно море небо утро книга лампа река школа'],
            2 => ['алгоритм клавиатура решение программа учитель ученик команда рисунок задача скорость'],
            default => ['последовательность программирование закономерность исследование ответственность воображение'],
        };
        return match ($difficulty) {
            1 => ['cat dog sun sky tree book door home blue green learn play river cloud'],
            2 => ['keyboard solution program teacher student pattern practice journey accuracy'],
            default => ['sequence programming investigation responsibility imagination architecture'],
        };
    }
}
