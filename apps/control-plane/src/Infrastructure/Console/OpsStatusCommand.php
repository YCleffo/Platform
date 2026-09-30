<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Infrastructure\Scheduler\OperationalStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:ops:status', description: 'Show safe Control Plane operational status')]
final class OpsStatusCommand extends Command
{
    public function __construct(private readonly OperationalStatus $status) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $byCode = array_column($this->status->rows(), null, 'job_code');
            foreach (['control-scheduler', 'control-outbox-worker'] as $component) {
                [$code] = $this->status->component($component);
                $byCode[$code] ??= ['job_code' => $code, 'last_result' => 'unknown',
                    'last_success' => null, 'last_failure' => null, 'consecutive_failures' => 0,
                    'last_duration_ms' => null, 'last_processed_count' => null,
                    'heartbeat_age_seconds' => null];
            }
            ksort($byCode);
            $table = (new Table($output))->setHeaders(['component/job', 'state', 'lastSuccess',
                'lastFailure', 'consecutiveFailures', 'lastDurationMs', 'lastProcessedCount',
                'heartbeatAgeSeconds']);
            foreach ($byCode as $row) {
                $code = $row['job_code'];
                $component = match ($code) {
                    '__scheduler__' => 'control-scheduler',
                    'control_outbox_worker' => 'control-outbox-worker',
                    default => null,
                };
                $age = $row['heartbeat_age_seconds'];
                $disabled = $row['last_result'] === 'disabled';
                $stale = false;
                if ($component !== null) {
                    [, $enabled, $timeout] = $this->status->component($component);
                    $disabled = !$enabled;
                    $stale = $enabled && $age !== null && (int) $age > $timeout;
                }
                $state = $disabled ? 'disabled' : ($age === null ? 'unknown' : ($stale ? 'stale'
                    : ((int) $row['consecutive_failures'] > 0 ? 'failed'
                    : ($row['last_result'] === 'succeeded' ? 'healthy' : 'waiting'))));
                $table->addRow([$component ?? $code, $state, $row['last_success'] ?? '-',
                    $row['last_failure'] ?? '-', $row['consecutive_failures'],
                    $row['last_duration_ms'] ?? '-', $row['last_processed_count'] ?? '-',
                    $age ?? '-']);
            }
            $table->render();
            $output->writeln(json_encode(['outboxBacklog' => $this->status->outboxBacklog()], JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('Operational status unavailable.');
            return Command::FAILURE;
        }
    }
}
