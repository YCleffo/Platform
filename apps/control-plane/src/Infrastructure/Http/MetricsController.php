<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Infrastructure\Scheduler\OperationalStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/** Token-protected Prometheus exposition for Commercial operational signals. */
final readonly class MetricsController
{
    #[Route('/metrics', name: 'control_metrics', methods: ['GET'])]
    public function __invoke(Request $request, OperationalStatus $status, Connection $db): Response
    {
        if (!$this->authorized($request)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $lines = [
            '# HELP platform_ready Local readiness after dependency and heartbeat checks.',
            '# TYPE platform_ready gauge',
        ];
        $ready = $status->ready();
        $lines[] = 'platform_ready{plane="commercial"} ' . ($ready ? '1' : '0');
        $dbUp = $this->probe(static fn(): bool => $db->fetchOne('SELECT 1') !== false);
        $lines[] = 'platform_dependency_up{plane="commercial",dependency="postgres"} ' . ($dbUp ? '1' : '0');

        try {
            $lines[] = '# HELP platform_background_job_heartbeat_age_seconds Age of the last worker heartbeat.';
            $lines[] = '# TYPE platform_background_job_heartbeat_age_seconds gauge';
            $lines[] = '# HELP platform_background_job_consecutive_failures Consecutive failed job runs.';
            $lines[] = '# TYPE platform_background_job_consecutive_failures gauge';
            $lines[] = '# HELP platform_background_job_last_duration_seconds Last job duration.';
            $lines[] = '# TYPE platform_background_job_last_duration_seconds gauge';
            $lines[] = '# HELP platform_background_job_last_processed_count Last processed batch count.';
            $lines[] = '# TYPE platform_background_job_last_processed_count gauge';
            foreach ($status->rows() as $row) {
                $job = $this->label((string) ($row['job_code'] ?? 'unknown'));
                $age = $row['heartbeat_age_seconds'] ?? null;
                if ($age !== null) {
                    $lines[] = 'platform_background_job_heartbeat_age_seconds{plane="commercial",worker="' . $job . '"} '
                        . (int) $age;
                }
                $lines[] = 'platform_background_job_consecutive_failures{plane="commercial",worker="' . $job . '"} '
                    . (int) ($row['consecutive_failures'] ?? 0);
                $duration = $row['last_duration_ms'] ?? null;
                if ($duration !== null) {
                    $lines[] = 'platform_background_job_last_duration_seconds{plane="commercial",worker="' . $job . '"} '
                        . ((int) $duration / 1000);
                }
                $processed = $row['last_processed_count'] ?? null;
                if ($processed !== null) {
                    $lines[] = 'platform_background_job_last_processed_count{plane="commercial",worker="' . $job . '"} '
                        . (int) $processed;
                }
            }
        } catch (Throwable) {
            $lines[] = 'platform_metrics_error{plane="commercial",section="jobs"} 1';
        }

        try {
            $outbox = $status->outboxBacklog();
            $lines[] = '# HELP platform_outbox_pending Events waiting for delivery.';
            $lines[] = '# TYPE platform_outbox_pending gauge';
            $lines[] = '# HELP platform_outbox_oldest_age_seconds Age of the oldest waiting event.';
            $lines[] = '# TYPE platform_outbox_oldest_age_seconds gauge';
            $lines[] = 'platform_outbox_pending{plane="commercial",queue="all"} ' . $outbox['pendingOutboxCount'];
            if ($outbox['oldestPendingAgeSeconds'] !== null) {
                $lines[] = 'platform_outbox_oldest_age_seconds{plane="commercial",queue="all"} '
                    . $outbox['oldestPendingAgeSeconds'];
            }
        } catch (Throwable) {
            $lines[] = 'platform_metrics_error{plane="commercial",section="outbox"} 1';
        }

        try {
            $financial = $status->financialBacklog();
            $lines[] = '# HELP platform_financial_backlog_items Items requiring financial reconciliation.';
            $lines[] = '# TYPE platform_financial_backlog_items gauge';
            $lines[] = 'platform_financial_backlog_items{plane="commercial",kind="paid_unapplied"} '
                . $financial['paidUnappliedCount'];
            $lines[] = 'platform_financial_backlog_items{plane="commercial",kind="recovery_required"} '
                . $financial['recoveryRequiredCount'];
            $lines[] = 'platform_financial_backlog_items{plane="commercial",kind="pending_compensation_refund"} '
                . $financial['pendingCompensationRefundCount'];
            if ($financial['oldestPendingCompensationAgeSeconds'] !== null) {
                $lines[] = '# HELP platform_financial_backlog_oldest_age_seconds Age of the oldest pending compensation.';
                $lines[] = '# TYPE platform_financial_backlog_oldest_age_seconds gauge';
                $lines[] = 'platform_financial_backlog_oldest_age_seconds{plane="commercial"} '
                    . $financial['oldestPendingCompensationAgeSeconds'];
            }
        } catch (Throwable) {
            $lines[] = 'platform_metrics_error{plane="commercial",section="financial"} 1';
        }

        return new Response(
            implode("\n", $lines) . "\n",
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function authorized(Request $request): bool
    {
        $token = getenv('METRICS_TOKEN');
        if (!is_string($token) || strlen($token) < 32) {
            return false;
        }
        $provided = $request->headers->get('Authorization');
        return is_string($provided)
            && str_starts_with($provided, 'Bearer ')
            && hash_equals('Bearer ' . $token, $provided);
    }

    /** @param callable(): bool $probe */
    private function probe(callable $probe): bool
    {
        try {
            return $probe();
        } catch (Throwable) {
            return false;
        }
    }

    private function label(string $value): string
    {
        return str_replace(["\\", "\"", "\n"], ["\\\\", "\\\"", "\\n"], $value);
    }
}


