<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Deployment\Application\RequestDeploymentUpdate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:deployments:update', description: 'Request a safe dedicated deployment release update')]
final class UpdateDeploymentCommand extends Command
{
    public function __construct(private readonly RequestDeploymentUpdate $updates) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('deployment-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('release-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('idempotency-key', null, InputOption::VALUE_REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $id = $this->updates->request((string) $input->getOption('deployment-id'),
                (string) $input->getOption('release-id'), (string) $input->getOption('idempotency-key'));
            $output->writeln($id);
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }
}
