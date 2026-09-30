<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Scheduler;

use Doctrine\DBAL\Connection;

/** Orchestrates one bounded call per due job; PostgreSQL session locks span that call. */
final class SchedulerLoop
{
    /** @var array<string, float> */
    private array $nextDue = [];

    /** @param list<ScheduledJob> $jobs */
    public function __construct(private Connection $db, private array $jobs,
        private \Closure $log, private string $owner = 'control') {}

    public function tick(?float $now = null): void
    {
        $now ??= microtime(true);
        foreach ($this->jobs as $job) {
            if (!$job->enabled || $now < ($this->nextDue[$job->code] ?? 0.0)) { continue; }
            $this->nextDue[$job->code] = $now + $job->intervalSeconds;
            $started = microtime(true);
            $key = 'scheduler:' . $this->owner . ':' . $job->code;
            $locked = false;
            try {
                $locked = in_array($this->db->fetchOne(
                    'SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$key]
                ), [true, 't', '1', 1], true);
                if (!$locked) { $this->report($job->code, 'skipped_lock', $started, 0); continue; }
                $count = ($job->run)($job->batchLimit);
                $this->report($job->code, 'ok', $started, (int) $count);
            } catch (JobSkipped $skip) {
                $this->report($job->code, 'skipped', $started, 0, $skip->reasonCode);
            } catch (\Throwable) {
                $this->report($job->code, 'failed', $started, 0, 'job_failed');
            } finally {
                if ($locked) {
                    try {
                        $this->db->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
                    } catch (\Throwable) {
                        $this->report($job->code, 'failed', $started, 0, 'lock_release_failed');
                    }
                }
            }
        }
    }

    public function run(): never
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
