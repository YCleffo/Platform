<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\Deployment\Application\StartDeploymentProvisioning;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'control:deployments:provision:start', description: 'Start application provisioning and emit a one-time bootstrap credential')]
final class StartDeploymentProvisioningCommand extends Command
{
    public function __construct(private readonly StartDeploymentProvisioning $provisioning) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('deployment-id', null, InputOption::VALUE_REQUIRED, 'Reserved deployment UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $id = $input->getOption('deployment-id');
            if (!is_string($id)) { throw new \DomainException('Deployment ID is required.'); }
            $output->writeln(json_encode($this->provisioning->start($id), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (\DomainException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
