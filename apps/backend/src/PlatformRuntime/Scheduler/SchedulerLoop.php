<?php

declare(strict_types=1);

namespace App\PlatformRuntime\Scheduler;

use Yiisoft\Db\Connection\ConnectionInterface;

/** Orchestrates one bounded call per due job; PostgreSQL session locks span that call. */
final class SchedulerLoop
{
    /** @var array<string, float> */
    private array $nextDue = [];
    private float $nextHeartbeat = 0.0;
    /** @var array<string, bool> */
    private array $disabledRecorded = [];

    /** @param list<ScheduledJob> $jobs */
    public function __construct(private ConnectionInterface $db, private array $jobs,
        private \Closure $log, private string $owner = 'core',
        private ?BackgroundJobStatusRecorder $recorder = null) {}

    public function tick(?float $now = null): void
    {
        $now ??= microtime(true);
        if ($this->recorder !== null && $now >= $this->nextHeartbeat) {
            $this->recorder->heartbeat('__scheduler__');
            $this->nextHeartbeat = $now + 10;
        }
        foreach ($this->jobs as $job) {
            if (!$job->enabled) {
                if ($this->recorder !== null && !isset($this->disabledRecorded[$job->code])) {
                    $this->recorder->skipped($job->code, 'disabled');
                    $this->disabledRecorded[$job->code] = true;
                }
                continue;
            }
            if ($now < ($this->nextDue[$job->code] ?? 0.0)) { continue; }
            $this->nextDue[$job->code] = $now + $job->intervalSeconds;
            $started = microtime(true);
            $key = 'scheduler:' . $this->owner . ':' . $job->code;
            $locked = false;
            try {
                $locked = in_array($this->db->createCommand(
                    'SELECT pg_try_advisory_lock(hashtextextended(:key, 0))', [':key' => $key]
                )->queryScalar(), [true, 't', '1', 1], true);
                if (!$locked) {
                    $this->recorder?->skipped($job->code, 'lock_busy');
                    $this->report($job->code, 'skipped_lock', $started, 0); continue;
                }
                $this->recorder?->started($job->code);
                $count = ($job->run)($job->batchLimit);
                $this->recorder?->succeeded($job->code, max(0, (int) ((microtime(true) - $started) * 1000)), (int) $count);
                $this->report($job->code, 'ok', $started, (int) $count);
            } catch (JobSkipped $skip) {
                $this->recorder?->skipped($job->code, $skip->reasonCode);
                $this->report($job->code, 'skipped', $started, 0, $skip->reasonCode);
            } catch (\Throwable) {
                $this->recorder?->failed($job->code, 'job_failed');
                $this->report($job->code, 'failed', $started, 0, 'job_failed');
            } finally {
                if ($locked) {
                    try {
                        $this->db->createCommand(
                            'SELECT pg_advisory_unlock(hashtextextended(:key, 0))', [':key' => $key]
                        )->queryScalar();
                    } catch (\Throwable) {
                        $this->recorder?->failed($job->code, 'lock_release_failed');
                        $this->report($job->code, 'failed', $started, 0, 'lock_release_failed');
                    }
                }
            }
        }
    }

    public function run(): void
    {
        while (true) {
            $this->tick();
            sleep(1);
        }
    }

    private function report(string $code, string $status, float $started, int $count,
        ?string $errorCode = null): void
    {
        ($this->log)(json_encode(['jobCode' => $code, 'status' => $status,
            'durationMs' => (int) ((microtime(true) - $started) * 1000),
            'processed' => $count, 'errorCode' => $errorCode], JSON_THROW_ON_ERROR));
    }
}
