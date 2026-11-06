<?php

declare(strict_types=1);

namespace App\Api;

use App\PlatformRuntime\Scheduler\OperationalStatus;
use Predis\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Token-protected Prometheus exposition for Core operational signals. */
final readonly class MetricsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        OperationalStatus $status,
        ConnectionInterface $db,
        ClientInterface $redis,
    ): ResponseInterface {
        if (!$this->authorized($request)) {
            return $responses->createResponse(404);
        }

        $lines = [
            '# HELP platform_ready Local readiness after dependency and heartbeat checks.',
            '# TYPE platform_ready gauge',
        ];
        $ready = $status->ready();
        $lines[] = 'platform_ready{plane="core"} ' . ($ready ? '1' : '0');
        $dbUp = $this->probe(static fn(): bool => $db->createCommand('SELECT 1')->queryScalar() !== false);
        $lines[] = 'platform_dependency_up{plane="core",dependency="postgres"} ' . ($dbUp ? '1' : '0');
        $redisUp = $this->probe(static fn(): bool => (string) $redis->ping() === 'PONG');
        $lines[] = 'platform_dependency_up{plane="core",dependency="redis"} ' . ($redisUp ? '1' : '0');

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
                    $lines[] = 'platform_background_job_heartbeat_age_seconds{plane="core",worker="' . $job . '"} ' . (int) $age;
                }
                $lines[] = 'platform_background_job_consecutive_failures{plane="core",worker="' . $job . '"} '
                    . (int) ($row['consecutive_failures'] ?? 0);
                $duration = $row['last_duration_ms'] ?? null;
                if ($duration !== null) {
                    $lines[] = 'platform_background_job_last_duration_seconds{plane="core",worker="' . $job . '"} '
                        . ((int) $duration / 1000);
                }
                $processed = $row['last_processed_count'] ?? null;
                if ($processed !== null) {
                    $lines[] = 'platform_background_job_last_processed_count{plane="core",worker="' . $job . '"} '
                        . (int) $processed;
                }
            }
        } catch (Throwable) {
            $lines[] = 'platform_metrics_error{plane="core",section="jobs"} 1';
        }

        try {
            $backlog = $db->createCommand(<<<'SQL'
                SELECT count(*) FILTER (WHERE status IN ('pending','failed','processing'))::integer AS pending,
                       count(*) FILTER (WHERE status = 'dead')::integer AS dead,
                       EXTRACT(EPOCH FROM (clock_timestamp() - min(occurred_at)
                           FILTER (WHERE status IN ('pending','failed','processing'))))::integer AS oldest_age
                FROM core.outbox_events
                SQL)->queryOne();
            $lines[] = '# HELP platform_outbox_pending Events waiting for delivery.';
            $lines[] = '# TYPE platform_outbox_pending gauge';
            $lines[] = '# HELP platform_outbox_dead Events dead-lettered.';
            $lines[] = '# TYPE platform_outbox_dead gauge';
            $lines[] = '# HELP platform_outbox_oldest_age_seconds Age of the oldest waiting event.';
            $lines[] = '# TYPE platform_outbox_oldest_age_seconds gauge';
            if (!is_array($backlog)) {
                throw new \RuntimeException('Core outbox metrics are unavailable.');
            }
            $lines[] = 'platform_outbox_pending{plane="core",queue="all"} ' . (int) ($backlog['pending'] ?? 0);
            $lines[] = 'platform_outbox_dead{plane="core",queue="all"} ' . (int) ($backlog['dead'] ?? 0);
            if (($backlog['oldest_age'] ?? null) !== null) {
                $lines[] = 'platform_outbox_oldest_age_seconds{plane="core",queue="all"} ' . (int) $backlog['oldest_age'];
            }
            $email = $status->emailBacklog();
            $lines[] = 'platform_outbox_pending{plane="core",queue="email"} ' . $email['pendingEmailCount'];
            if ($email['oldestPendingAgeSeconds'] !== null) {
                $lines[] = 'platform_outbox_oldest_age_seconds{plane="core",queue="email"} '
                    . $email['oldestPendingAgeSeconds'];
            }
        } catch (Throwable) {
            $lines[] = 'platform_metrics_error{plane="core",section="outbox"} 1';
        }

        return $responses->createResponse(200)
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($streams->createStream(implode("\n", $lines) . "\n"));
    }

    private function authorized(ServerRequestInterface $request): bool
    {
        $token = getenv('METRICS_TOKEN');
        if (!is_string($token) || strlen($token) < 32) {
            return false;
        }
        $provided = $request->getHeaderLine('Authorization');
        return str_starts_with($provided, 'Bearer ')
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
