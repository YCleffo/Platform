<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Payment\Provider\ProviderPaymentFailure;

final readonly class RenewalDunningPolicy
{
    /** @var list<int> */
    private array $retryDelays;

    public function __construct(
        private int $maxAttempts,
        string $retryDelaysSeconds,
        private int $graceSeconds,
        private int $uncertainRetrySeconds,
    ) {
        $delays = trim($retryDelaysSeconds) === '' ? [] : array_map('trim', explode(',', $retryDelaysSeconds));
        if ($maxAttempts < 1 || $maxAttempts > 20 || count($delays) !== $maxAttempts - 1
            || $graceSeconds < 60 || $graceSeconds > 2592000
            || $uncertainRetrySeconds < 10 || $uncertainRetrySeconds > 86400) {
            throw new \InvalidArgumentException('Invalid renewal dunning policy configuration.');
        }
        $parsed = [];
        foreach ($delays as $delay) {
            if ($delay === '' || preg_match('/\A[1-9][0-9]*\z/D', $delay) !== 1 || (int) $delay > $graceSeconds) {
                throw new \InvalidArgumentException('Invalid renewal retry delay configuration.');
            }
            $parsed[] = (int) $delay;
        }
        if ($parsed !== array_values(array_unique($parsed))) {
            throw new \InvalidArgumentException('Renewal retry delays must be unique.');
        }
        for ($i = 1; $i < count($parsed); ++$i) {
            if ($parsed[$i] <= $parsed[$i - 1]) throw new \InvalidArgumentException('Renewal retry delays must increase.');
        }
        $this->retryDelays = $parsed;
    }

    public function maximumAttempts(): int { return $this->maxAttempts; }
    public function graceDeadline(\DateTimeImmutable $periodEnd): \DateTimeImmutable { return $periodEnd->modify('+' . $this->graceSeconds . ' seconds'); }
    public function uncertainRetryAt(\DateTimeImmutable $now): \DateTimeImmutable { return $now->modify('+' . $this->uncertainRetrySeconds . ' seconds'); }
    public function retryAt(int $completedAttempts, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($completedAttempts >= $this->maxAttempts) return null;
        return $now->modify('+' . $this->retryDelays[$completedAttempts - 1] . ' seconds');
    }
    public function consumesAttempt(string $failureClass): bool
    {
        return $failureClass !== ProviderPaymentFailure::OPERATIONAL;
    }
    public function mayRetry(string $failureClass): bool
    {
        return in_array($failureClass, [ProviderPaymentFailure::RETRYABLE, ProviderPaymentFailure::UNKNOWN], true);
    }
}
