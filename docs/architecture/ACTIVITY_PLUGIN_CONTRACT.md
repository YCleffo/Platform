# PLATFORM — MODULAR ACTIVITY ENGINE & PLUGIN CONTRACT

**Status:** Architectural Specification & Extension Interface  
**Milestone:** PLATFORM_08_A / PLATFORM_08_D  
**Author:** Principal Software Architect & Education Systems Lead  

---

## 1. Context & Motivation

Historically, `ActivityHandlerRegistry::handler()` selected activity evaluators using hardcoded `match` expressions (`platform-typing`, `platform-logic`).  
In PLATFORM_08, the Activity Engine is generalized into an extensible, pluggable contract where any subject module (or third-party educational trainer) can register exercise generators, validators, and evaluation sinks without modifying `EducationService`.

---

## 2. Activity Plugin Lifecycle & Contract

Every activity plugin MUST implement `ActivityPluginInterface`:

```php
namespace App\Education\Activity;

interface ActivityPluginInterface
{
    /** Unique activity identifier, e.g. 'platform-typing', 'platform-logic', 'kiber-code-quest' */
    public function code(): string;

    /** Semantic version for config migrations */
    public function version(): string;

    /** Human-readable title and localized descriptions */
    public function metadata(): ActivityMetadata;

    /** JSON Schema validating educator-assigned configuration */
    public function configSchema(): array;

    /** Generate exercise payload for a student attempt */
    public function generateExercise(Scope $scope, array $effectiveConfig): array;

    /** Validate student attempt inputs in progress (state snapshot) */
    public function validateState(array $currentState, array $inputEvent): array;

    /** Server-side evaluation of finished attempt to compute score/grade */
    public function evaluateAttempt(array $attemptState, array $rawMetrics): ActivityEvaluationResult;

    /** Project evaluation into overall curriculum progress metrics */
    public function projectProgress(ActivityEvaluationResult $result): array;
}
```

---

## 3. Storage & Result Invariants

1. **Server-Side Verification:**
   - Clients CANNOT report pre-calculated final scores or mastery levels directly.
   - Client sends telemetry (keystrokes, timestamps, sequence inputs); server runs deterministic verification.
2. **Attempt Idempotency:**
   - Attempt creation requires idempotency key (`clientKey` / `uuidv7`).
   - Duplicate finish attempts return identical evaluated records.
3. **Homework Integration:**
   - An activity assignment can be attached to a homework recipient. Completing the attempt links the attempt ID to `education.homework_submissions`.

---

## 4. Demonstration Plugin (Third Activity)

Target demonstration plugin: `platform-math-sprint`
- Generates timed mental arithmetic sequences with seeds.
- Evaluates speed, accuracy, streaks, and error distribution.
- Conforms 100% to DI autowiring without hardcoded matches in Education core.
