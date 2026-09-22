<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

use App\Core\Module\Application\ModuleRepository;
use InvalidArgumentException;
use LogicException;

final readonly class FeatureFlagService
{
    public function __construct(
        private FeatureFlagRepository $repository,
        private ModuleRepository $modules,
    ) {}

    public function isEnabled(string $code): bool
    {
        $flag = $this->repository->findByCode($code);
        if ($flag === null || !$this->isEffectivelyAvailable($flag) || $flag->rolloutPercent === 0) {
            return false;
        }
        if ($flag->rolloutPercent < 100) {
            throw new LogicException('Partial feature rollout requires a stable subject key.');
        }

        return true;
    }

    public function isEnabledFor(string $code, string $subjectKey): bool
    {
        return $this->evaluate($code, $subjectKey)->enabled;
    }

    public function evaluate(string $code, string $subjectKey): FeatureEvaluation
    {
        if (trim($subjectKey) === '') {
            throw new InvalidArgumentException('A stable non-blank rollout subject is required.');
        }

        $flag = $this->repository->findByCode($code);
        if ($flag === null) {
            return new FeatureEvaluation(false, []);
        }
        if (!$this->isEffectivelyAvailable($flag) || $flag->rolloutPercent === 0) {
            return new FeatureEvaluation(false, $flag->config);
        }
        if ($flag->rolloutPercent === 100) {
            return new FeatureEvaluation(true, $flag->config);
        }

        $bucket = (int) hexdec(substr(hash('sha256', $flag->code . "\0" . $subjectKey), 0, 8)) % 10_000;
        return new FeatureEvaluation($bucket < $flag->rolloutPercent * 100, $flag->config);
    }

    private function isEffectivelyAvailable(FeatureFlag $flag): bool
    {
        if (!$flag->enabled) {
            return false;
        }

        return $flag->moduleCode === null
            || ($this->modules->findByCode($flag->moduleCode)?->enabled ?? false);
    }
}
