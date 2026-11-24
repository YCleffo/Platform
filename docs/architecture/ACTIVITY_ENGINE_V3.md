# PLATFORM — DYNAMIC ACTIVITY ENGINE (V3)
## Extensible Activity Plugin Architecture

**Status:** APPROVED & DEPLOYED  
**Version:** 3.0.0 (Education 08 Baseline)  
**Author:** Education Systems Engineering & Architecture  
**Date:** November 24, 2026

---

## 1. Architectural Motivation

Prior to Education 08, the Activity Engine contained hardcoded switches:
```php
// Old legacy pattern:
$exercise = match ($activityCode) {
    'platform-typing' => $this->typingHandler->generateExercise($config),
    'platform-logic' => $this->logicHandler->generateExercise($config),
    default => throw new \InvalidArgumentException("Unknown activity: $activityCode")
};
```
This prohibited modular additions and violated the Open/Closed Principle. In Education 08, we introduced the **Dynamic Activity Plugin Registry**.

---

## 2. Core Contracts & Interfaces

### `ActivityPluginInterface`
```php
namespace Platform\Education\Application;

interface ActivityPluginInterface
{
    public function activityCode(): string;
    public function generateExercise(array $config): array;
    public function evaluateAttempt(array $exercise, array $state, int $elapsedSeconds): array;
    public function validateConfig(array $config): array;
    public function defaultConfig(): array;
    public function configSchema(): array;
}
```

### Dynamic Registry (`ActivityHandlerRegistry`)
```php
namespace Platform\Education\Application;

class ActivityHandlerRegistry
{
    private array $handlers = [];

    public function register(ActivityPluginInterface $handler): void
    {
        $this->handlers[$handler->activityCode()] = $handler;
    }

    public function get(string $activityCode): ActivityPluginInterface
    {
        if (!isset($this->handlers[$activityCode])) {
            throw new \InvalidArgumentException("Unknown activity plugin: {$activityCode}");
        }
        return $this->handlers[$activityCode];
    }

    public function has(string $activityCode): bool
    {
        return isset($this->handlers[$activityCode]);
    }

    public function all(): array
    {
        return $this->handlers;
    }
}
```

---

## 3. Registered Activity Plugins

1. **`TypingActivityHandler`** (`platform-typing`):
   - Touch typing trainer with duration presets (15s, 30s, 60s, 90s, 120s), language selection (RU, EN), modes (words, sentences, numbers, code, accuracy).
   - WPM telemetry verification, error count calculations, accuracy percentages.
2. **`LogicActivityHandler`** (`platform-logic`):
   - Multi-round pattern recognition and logic deduction.
   - Dynamic round generation, deterministic scoring.
3. **`AssessmentActivityHandler`** (`platform-assessment`):
   - Structured tests with versioned question bank items.
   - Anti-cheating answer key masking in student payload.
   - Server-side deterministic grading with manual review override.

---

## 4. Homework & Journal Integration

- **`HomeworkForm.vue`**: Allows teachers to select an assessment from the assessment catalog or configure typing/logic constraints.
- **`StudentHomeworkView.vue`**: Seamlessly boots `StudentAssessmentRunner.vue` or `TypingTrainer.vue` in homework mode, submitting attempt IDs directly into homework solutions.
- **`JournalView.vue`**: Reflects automatic grading results and activity status directly into the group journal matrix.
