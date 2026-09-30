<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Console;

use ControlPlane\M2m\DeploymentCredentialLifetime;
use ControlPlane\M2m\DeploymentCredentialService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'control:deployments:credential:rotate', description: 'Rotate the single active deployment runtime credential')]
final class RotateDeploymentCredentialCommand extends Command
{
    public function __construct(private readonly DeploymentCredentialService $credentials,
        private readonly DeploymentCredentialLifetime $lifetime) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('deployment-id', null, InputOption::VALUE_REQUIRED, 'Deployment UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $deploymentId = $input->getOption('deployment-id');
            if (!is_string($deploymentId) || !Uuid::isValid($deploymentId)) {
                throw new \DomainException('Invalid deployment ID.');
            }
            $issued = $this->credentials->rotateActiveRuntime($deploymentId, $this->lifetime->expiresAt());
            $output->writeln(json_encode(['credential' => $issued['credential']['credentialId'] . '.' . $issued['secret'],
                'expiresAt' => $issued['credential']['expiresAt'], 'scopes' => $issued['credential']['scopes']],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('Deployment credential rotation failed.');
            return Command::FAILURE;
        }
    }
}
