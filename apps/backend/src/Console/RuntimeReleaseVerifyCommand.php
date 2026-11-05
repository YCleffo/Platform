<?php

declare(strict_types=1);

namespace App\Console;

use App\PlatformRuntime\Scheduler\OperationalStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

#[AsCommand(name: 'runtime:release:verify', description: 'Verify applied release metadata and fresh worker heartbeats')]
final class RuntimeReleaseVerifyCommand extends Command
{
    public function __construct(private readonly ConnectionInterface $db, private readonly OperationalStatus $status)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('started-at', null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $started = new \DateTimeImmutable((string) $input->getOption('started-at'));
            $row = $this->db->createCommand('SELECT release_id,application_version,release_channel::text FROM platform_runtime.installations WHERE singleton_key=1')->queryOne();
            if (!is_array($row) || $row['release_id'] !== (string) getenv('PLATFORM_RELEASE_ID')
                || $row['application_version'] !== (string) getenv('APPLICATION_VERSION')
                || $row['release_channel'] !== (string) getenv('PLATFORM_RELEASE_CHANNEL') || !$this->status->ready()) {
                return Command::FAILURE;
            }
            $fresh = $this->db->createCommand("SELECT count(*) FROM platform_runtime.background_job_status WHERE job_code IN ('__scheduler__','backend_email_worker') AND heartbeat_at >= :started", [':started' => $started->format('c')])->queryScalar();
            return (int) $fresh === 2 ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable) {
            return Command::FAILURE;
        }
    }
}
