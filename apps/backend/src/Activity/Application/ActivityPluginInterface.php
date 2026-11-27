<?php

declare(strict_types=1);

namespace App\Activity\Application;

/**
 * Universal Activity Engine Plugin Contract v2.
 * All activity genres (assessment, typing, math sprint, logic, memory, etc.) implement this uniform interface.
 */
interface ActivityPluginInterface
{
    public function code(): string;

    public function version(): int;

    public function title(): string;

    public function category(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /** @return array<string, mixed> */
    public function configSchema(): array;

    /** @return array<string, mixed> */
    public function defaultConfig(): array;

    /**
     * Config keys that a participant may safely customize during open practice (never during controlled assignments).
     *
     * @return list<string>
     */
    public function safeParticipantConfigKeys(): array;

    /**
     * Generates deterministic or configured exercise payload for a new attempt.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed>|null $content
     * @return array<string, mixed>
     */
    public function generateExercise(array $config, int $seed, ?array $content = null): array;

    /**
     * Evaluates a submitted attempt strictly on the server.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $exercise
     * @param array<string, mixed> $submission
     * @param array<string, mixed> $telemetry
     * @param array<string, mixed>|null $content
     * @return array{
     *   rawScore: float,
     *   maxScore: float,
     *   percentage: float,
     *   passed: bool,
     *   requiresManualReview: bool,
     *   breakdown: list<array<string, mixed>>,
     *   metrics: array<string, mixed>,
     *   telemetryVerified: bool,
     *   feedback: string
     * }
     */
    public function evaluate(
        array $config,
        array $exercise,
        array $submission,
        array $telemetry = [],
        ?array $content = null,
    ): array;
}
