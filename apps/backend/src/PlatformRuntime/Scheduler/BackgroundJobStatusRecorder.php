<?php

declare(strict_types=1);

namespace App\PlatformRuntime\Scheduler;

use Yiisoft\Db\Connection\ConnectionInterface;

/** One durable row per job. No exception text or payload crosses this boundary. */
final readonly class BackgroundJobStatusRecorder
{
    public function __construct(private ConnectionInterface $db) {}

    public function heartbeat(string $code): void
    {
        $this->checkCode($code);
        $this->db->createCommand(<<<'SQL'
INSERT INTO platform_runtime.background_job_status (job_code) VALUES (:code)
ON CONFLICT (job_code) DO UPDATE SET heartbeat_at = now(), updated_at = now()
SQL, [':code' => $code])->execute();
    }

    public function started(string $code): void
    {
        $this->checkCode($code);
        $this->db->createCommand(<<<'SQL'
INSERT INTO platform_runtime.background_job_status (job_code, last_started_at, last_result)
VALUES (:code, now(), 'started')
ON CONFLICT (job_code) DO UPDATE SET last_started_at = now(), last_result = 'started',
    heartbeat_at = now(), updated_at = now()
SQL, [':code' => $code])->execute();
    }

    public function succeeded(string $code, int $durationMs, int $processed): void
    {
        $this->checkCode($code);
        if ($durationMs < 0 || $processed < 0) {
            throw new \InvalidArgumentException('Invalid job result.');
        }
        $this->db->createCommand(<<<'SQL'
INSERT INTO platform_runtime.background_job_status
    (job_code, last_succeeded_at, last_duration_ms, last_processed_count, last_result)
VALUES (:code, now(), :duration, :processed, 'succeeded')
ON CONFLICT (job_code) DO UPDATE SET last_succeeded_at = now(),
    last_duration_ms = EXCLUDED.last_duration_ms, last_processed_count = EXCLUDED.last_processed_count,
    consecutive_failures = 0, last_error_code = NULL, last_result = 'succeeded',
    heartbeat_at = now(), updated_at = now()
SQL, [':code' => $code, ':duration' => $durationMs, ':processed' => $processed])->execute();
    }

    public function failed(string $code, string $errorCode): void
    {
        $this->checkCode($code);
        $this->checkError($errorCode);
        $this->db->createCommand(<<<'SQL'
INSERT INTO platform_runtime.background_job_status
    (job_code, last_failed_at, consecutive_failures, last_error_code, last_result)
VALUES (:code, now(), 1, :error, 'failed')
ON CONFLICT (job_code) DO UPDATE SET last_failed_at = now(),
    consecutive_failures = platform_runtime.background_job_status.consecutive_failures + 1,
    last_error_code = EXCLUDED.last_error_code, last_result = 'failed',
    heartbeat_at = now(), updated_at = now()
SQL, [':code' => $code, ':error' => $errorCode])->execute();
    }

    public function skipped(string $code, string $reason): void
    {
        $this->checkCode($code);
        if (!in_array($reason, ['disabled', 'lock_busy', 'policy_missing',
            'privacy_policy_missing', 'automation_actor_missing', 'entitlement_sync_unconfigured'], true)) {
            throw new \InvalidArgumentException('Invalid job skip reason.');
        }
        $result = $reason === 'disabled' ? 'disabled' : 'skipped';
        $this->db->createCommand(<<<'SQL'
INSERT INTO platform_runtime.background_job_status (job_code, last_result)
VALUES (:code, :result)
ON CONFLICT (job_code) DO UPDATE SET last_result = EXCLUDED.last_result,
    heartbeat_at = now(), updated_at = now()
SQL, [':code' => $code, ':result' => $result])->execute();
    }

    private function checkCode(string $code): void
    {
        if ($code !== '__scheduler__' && preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/D', $code) !== 1) {
            throw new \InvalidArgumentException('Invalid job code.');
        }
    }

    private function checkError(string $code): void
    {
        if (strlen($code) > 120 || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $code) !== 1) {
            throw new \InvalidArgumentException('Invalid machine error code.');
        }
    }
}
