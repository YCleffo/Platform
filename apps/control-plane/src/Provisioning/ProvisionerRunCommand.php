<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'provisioner:run', description: 'Process bounded deployment provision and deprovision operations')]
final class ProvisionerRunCommand extends Command
{
    public function __construct(private readonly ProvisionDeploymentExecutor $executor,
        private readonly DeprovisionDeploymentExecutor $deprovision) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run one bounded batch and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $enabled = getenv('PROVISIONER_ENABLED') === 'true';
        $batch = filter_var(getenv('PROVISIONER_BATCH_SIZE') ?: '2', FILTER_VALIDATE_INT);
        $poll = filter_var(getenv('PROVISIONER_POLL_INTERVAL_SECONDS') ?: '30', FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 20 || $poll === false || $poll < 5 || $poll > 3600) {
            return Command::FAILURE;
        }
        do {
            $status = $this->readStatus();
            $status['heartbeatAt'] = gmdate('Y-m-d\TH:i:s\Z');
            if ($enabled) {
                $status['lastAttemptAt'] = $status['heartbeatAt'];
                try {
                    $deprovisioned = $this->deprovision->runBatch($batch);
                    $count = $this->executor->runBatch($batch);
                    $status['lastSuccessAt'] = gmdate('Y-m-d\TH:i:s\Z');
                    $status['consecutiveFailures'] = 0;
                    if ($count > 0) { $output->writeln('Provision operations processed: ' . $count); }
                    if ($deprovisioned > 0) { $output->writeln('Deprovision operations processed: ' . $deprovisioned); }
                } catch (\Throwable) {
                    $status['lastFailureAt'] = gmdate('Y-m-d\TH:i:s\Z');
                    $status['consecutiveFailures'] = min(1000000, $status['consecutiveFailures'] + 1);
                    $output->writeln('Provisioner batch failed.');
                }
            }
            $this->writeStatus($status);
            if ($input->getOption('once')) { break; }
            sleep($poll);
        } while (true);
        return Command::SUCCESS;
    }

    /** @return array{heartbeatAt:?string,lastAttemptAt:?string,lastSuccessAt:?string,lastFailureAt:?string,consecutiveFailures:int} */
    private function readStatus(): array
    {
        $defaults = ['heartbeatAt' => null, 'lastAttemptAt' => null,
            'lastSuccessAt' => null, 'lastFailureAt' => null, 'consecutiveFailures' => 0];
        $file = '/run/provisioning-secrets/status.json';
        $value = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($value)) { return $defaults; }
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $value) || ($key === 'consecutiveFailures'
                ? !is_int($value[$key]) : (!is_null($value[$key]) && !is_string($value[$key])))) {
                $value[$key] = $default;
            }
        }
        return array_intersect_key($value, $defaults);
    }

    private function writeStatus(array $status): void
    {
        $file = '/run/provisioning-secrets/status.json';
        $temporary = $file . '.partial';
        file_put_contents($temporary, json_encode($status, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($temporary, 0600);
        rename($temporary, $file);
    }
}
