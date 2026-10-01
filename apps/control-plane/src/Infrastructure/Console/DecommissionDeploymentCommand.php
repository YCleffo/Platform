<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Deployment\Application\RequestDeploymentDecommission;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:deployments:decommission', description: 'Request asynchronous local dedicated deployment decommission')]
final class DecommissionDeploymentCommand extends Command
{
    public function __construct(private readonly RequestDeploymentDecommission $requests) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('deployment-id', null, InputOption::VALUE_REQUIRED, 'Deployment UUID')
            ->addOption('confirm-installation-id', null, InputOption::VALUE_REQUIRED, 'Installation UUID to confirm')
            ->addOption('idempotency-key', null, InputOption::VALUE_REQUIRED, 'Unique operator request key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deploymentId = $input->getOption('deployment-id');
        $installationId = $input->getOption('confirm-installation-id');
        $key = $input->getOption('idempotency-key');
        if (!is_string($deploymentId) || !is_string($installationId) || !is_string($key)) {
            throw new \InvalidArgumentException('All three decommission options are required.');
        }
        $operationId = $this->requests->request($deploymentId, $installationId, $key);
        $output->writeln('Decommission requested: ' . $operationId);
        return Command::SUCCESS;
    }
}
