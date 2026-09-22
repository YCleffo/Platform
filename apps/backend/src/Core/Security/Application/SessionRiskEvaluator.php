<?php

declare(strict_types=1);

namespace App\Core\Security\Application;

use App\Core\Security\Domain\SessionRiskAssessment;
use App\Core\Security\Domain\SessionRiskInput;
use App\Core\Security\Domain\SessionRiskLevel;

final readonly class SessionRiskEvaluator
{
    public function evaluate(SessionRiskInput $input): SessionRiskAssessment
    {
        $score = 0;
        $signals = [];

        if (
            $input->currentFingerprintHash !== null
            && $input->currentFingerprintHash !== $input->previousFingerprintHash
        ) {
            $score += 25;
            $signals[] = 'new_device';
        }
        if (
            $input->currentClientInstanceId !== null
            && $input->currentClientInstanceId !== $input->previousClientInstanceId
        ) {
            $score += 20;
            $signals[] = 'new_client_instance';
        }
        if (
            $this->changed($input->previousBrowserName, $input->currentBrowserName)
            || $this->changed($input->previousOsName, $input->currentOsName)
        ) {
            $score += 20;
            $signals[] = 'major_user_agent_change';
        }

        $countryChanged = $this->changed(
            $input->previousCountryCode,
            $input->currentCountryCode,
        );
        if ($countryChanged) {
            $score += 25;
            $signals[] = 'country_change';
        }
        if (
            $input->geoTimezone !== null
            && $input->geoTimezone !== $input->resolvedTimezone
        ) {
            $score += 10;
            $signals[] = 'timezone_mismatch';
        }
        if (
            $countryChanged
            && $input->previousSeenAt !== null
            && abs($input->occurredAt->getTimestamp() - $input->previousSeenAt->getTimestamp()) < 7200
        ) {
            $score += 30;
            $signals[] = 'fast_country_change';
        }

        $level = match (true) {
            $score >= 60 => SessionRiskLevel::HIGH_RISK,
            $score >= 25 => SessionRiskLevel::REVIEW,
            default => SessionRiskLevel::NORMAL,
        };

        return new SessionRiskAssessment($level, $score, $signals);
    }

    private function changed(?string $previous, ?string $current): bool
    {
        return $previous !== null
            && $current !== null
            && strcasecmp($previous, $current) !== 0;
    }
}
